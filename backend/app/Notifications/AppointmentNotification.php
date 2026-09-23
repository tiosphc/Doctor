<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

class AppointmentNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public const AUDIENCE_CUSTOMER = 'customer';

    public const AUDIENCE_DOCTOR = 'doctor';

    public const AUDIENCE_ADMIN = 'admin';

    public const AUDIENCE_STAFF = 'staff';

    public const AUDIENCE_OPERATIONS = 'operations';

    public const EVENT_CREATED = 'appointment_created';

    public const EVENT_CONFIRMED = 'appointment_confirmed';

    public const EVENT_RESCHEDULED = 'appointment_rescheduled';

    public const EVENT_CANCELLED = 'appointment_cancelled';

    public const EVENT_REMINDER = 'appointment_reminder';

    public const EVENT_CHECKED_IN = 'appointment_checked_in';

    public const EVENT_TREATMENT_DONE = 'appointment_treatment_done';

    public const EVENT_COMPLETED = 'appointment_completed';

    /** @var list<string> */
    public const EVENTS = [
        self::EVENT_CREATED,
        self::EVENT_CONFIRMED,
        self::EVENT_RESCHEDULED,
        self::EVENT_CANCELLED,
        self::EVENT_REMINDER,
        self::EVENT_CHECKED_IN,
        self::EVENT_TREATMENT_DONE,
        self::EVENT_COMPLETED,
    ];

    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly Appointment $appointment,
        public readonly string $event,
        public readonly array $context = [],
    ) {
        if (! in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException("Unsupported appointment notification event [{$event}].");
        }
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        if (in_array($this->context['audience'] ?? null, [
            self::AUDIENCE_ADMIN,
            self::AUDIENCE_OPERATIONS,
            self::AUDIENCE_DOCTOR,
            self::AUDIENCE_STAFF,
        ], true)) {
            return ['database'];
        }

        return $notifiable instanceof AnonymousNotifiable
            ? ['mail']
            : ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content($notifiable);
        $message = (new MailMessage)
            ->subject($content['title'])
            ->greeting('Xin chào '.($this->recipientName($notifiable) ?? 'quý khách').',')
            ->line($content['message'])
            ->line('Dịch vụ: '.$this->appointment->service->name)
            ->line('Bác sĩ: '.$this->appointment->doctor->name);

        if ($this->event === self::EVENT_RESCHEDULED && isset($content['previous_schedule'])) {
            $message
                ->line('Lịch cũ: '.$this->formatSchedule($content['previous_schedule']))
                ->line('Lịch mới: '.$this->formatSchedule($content['new_schedule']));
        } else {
            $message
                ->line('Ngày khám: '.$this->appointment->appointment_date->format('d/m/Y'))
                ->line('Thời gian: '.substr($this->appointment->start_time, 0, 5).' - '.substr($this->appointment->end_time, 0, 5));
        }

        return $message
            ->action('Xem lịch hẹn', $content['action_url'])
            ->line('Nếu cần hỗ trợ, vui lòng liên hệ với phòng khám.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->content($notifiable);
    }

    /** @return array<string, mixed> */
    private function content(object $notifiable): array
    {
        $content = match ($this->event) {
            self::EVENT_CREATED => [
                'title' => 'Đặt lịch thành công',
                'message' => 'Lịch hẹn của bạn đã được tiếp nhận và đang chờ phòng khám xác nhận.',
            ],
            self::EVENT_CONFIRMED => [
                'title' => 'Lịch hẹn đã được xác nhận',
                'message' => 'Phòng khám đã xác nhận lịch hẹn của bạn.',
            ],
            self::EVENT_RESCHEDULED => [
                'title' => 'Lịch hẹn của bạn đã được thay đổi',
                'message' => 'Lịch hẹn của bạn đã được cập nhật sang thời gian mới.',
            ],
            self::EVENT_CANCELLED => [
                'title' => 'Lịch hẹn đã được huỷ',
                'message' => 'Lịch hẹn của bạn đã được huỷ theo yêu cầu hoặc cập nhật từ phòng khám.',
            ],
            self::EVENT_REMINDER => [
                'title' => 'Nhắc lịch khám',
                'message' => 'Bạn có lịch khám sắp tới tại Junie. Vui lòng sắp xếp thời gian đến đúng hẹn.',
            ],
            self::EVENT_CHECKED_IN => [
                'title' => 'Khách hàng đã check-in',
                'message' => $this->customerName().' đã check-in cho lịch '.$this->appointment->service->name.'.',
            ],
            self::EVENT_TREATMENT_DONE => [
                'title' => 'Bác sĩ đã hoàn tất điều trị',
                'message' => 'Bác sĩ '.$this->appointment->doctor->name.' đã hoàn tất điều trị cho '.$this->customerName().'.',
            ],
            self::EVENT_COMPLETED => [
                'title' => 'Lịch hẹn của bạn đã hoàn tất',
                'message' => 'Lịch hẹn của bạn đã được phòng khám hoàn tất. Cảm ơn bạn đã sử dụng dịch vụ.',
            ],
        };

        if (($this->context['audience'] ?? null) === self::AUDIENCE_DOCTOR) {
            $content = $this->doctorContent($content);
        } elseif (in_array($this->context['audience'] ?? null, [
            self::AUDIENCE_ADMIN,
            self::AUDIENCE_OPERATIONS,
            self::AUDIENCE_STAFF,
        ], true)) {
            $content = $this->internalContent($content);
        }

        $content['message'] = 'Mã lịch hẹn '.$this->bookingReference().'. '.$content['message'];

        $payload = [
            'event' => $this->event,
            'title' => $content['title'],
            'message' => $content['message'],
            'appointment_id' => $this->appointment->id,
            'booking_code' => $this->appointment->booking_code,
            'action_url' => $this->actionUrl($notifiable),
            'status' => $this->appointment->status,
            'source' => $this->context['source'] ?? null,
            'actor_role' => $this->context['actor_role'] ?? null,
            'audience' => $this->context['audience'] ?? self::AUDIENCE_CUSTOMER,
        ];

        if (($this->context['audience'] ?? null) === self::AUDIENCE_DOCTOR
            && in_array($this->event, [self::EVENT_CREATED, self::EVENT_CHECKED_IN], true)) {
            $payload['data'] = [
                'customer_name' => $this->customerName(),
                'service_name' => $this->appointment->service->name,
                'doctor_name' => $this->appointment->doctor->name,
                'appointment_date' => $this->appointment->appointment_date->toDateString(),
                'start_time' => substr($this->appointment->start_time, 0, 5),
                'end_time' => substr($this->appointment->end_time, 0, 5),
            ];
        }

        if ($this->event === self::EVENT_TREATMENT_DONE) {
            $payload['data'] = [
                'customer_name' => $this->customerName(),
                'service_name' => $this->appointment->service->name,
                'doctor_name' => $this->appointment->doctor->name,
                'appointment_date' => $this->appointment->appointment_date->toDateString(),
                'start_time' => substr($this->appointment->start_time, 0, 5),
                'end_time' => substr($this->appointment->end_time, 0, 5),
                'source' => $this->context['source'] ?? null,
                'actor_role' => $this->context['actor_role'] ?? null,
            ];
        }

        if ($this->event === self::EVENT_RESCHEDULED
            && is_array($this->context['previous_schedule'] ?? null)
            && is_array($this->context['new_schedule'] ?? null)) {
            $payload['previous_schedule'] = $this->context['previous_schedule'];
            $payload['new_schedule'] = $this->context['new_schedule'];
            $payload['data'] = [
                'customer_name' => $this->customerName(),
                'service_name' => $this->appointment->service->name,
                'doctor_name' => $this->appointment->doctor->name,
                'old_appointment_date' => $this->context['previous_schedule']['appointment_date'] ?? null,
                'old_start_time' => $this->context['previous_schedule']['start_time'] ?? null,
                'old_end_time' => $this->context['previous_schedule']['end_time'] ?? null,
                'new_appointment_date' => $this->context['new_schedule']['appointment_date'] ?? null,
                'new_start_time' => $this->context['new_schedule']['start_time'] ?? null,
                'new_end_time' => $this->context['new_schedule']['end_time'] ?? null,
            ];
        }

        return $payload;
    }

    /** @param array{title: string, message: string} $content */
    private function internalContent(array $content): array
    {
        if ($this->event === self::EVENT_CREATED) {
            return [
                'title' => 'Có lịch hẹn mới',
                'message' => $this->customerName().' vừa đặt lịch '.$this->appointment->service->name.' với '.$this->appointment->doctor->name.'.',
            ];
        }

        if ($this->event === self::EVENT_RESCHEDULED
            && is_array($this->context['previous_schedule'] ?? null)
            && is_array($this->context['new_schedule'] ?? null)) {
            $customer = $this->customerName();
            $oldSchedule = $this->formatSchedule($this->context['previous_schedule']);
            $newSchedule = $this->formatSchedule($this->context['new_schedule']);

            return [
                'title' => 'Khách hàng đã đổi lịch hẹn',
                'message' => "{$customer} đã đổi lịch {$this->appointment->service->name} với {$this->appointment->doctor->name} từ {$oldSchedule} sang {$newSchedule}.",
            ];
        }

        if ($this->event === self::EVENT_CANCELLED) {
            return [
                'title' => 'Lịch hẹn đã bị huỷ',
                'message' => $this->customerName().' đã huỷ lịch '.$this->appointment->service->name.' với '.$this->appointment->doctor->name.'.',
            ];
        }

        return $content;
    }

    /** @param array{title: string, message: string} $content */
    private function doctorContent(array $content): array
    {
        return match ($this->event) {
            self::EVENT_CREATED => [
                'title' => 'Có lịch hẹn mới',
                'message' => 'Bạn có lịch hẹn mới với '.$this->customerName().' cho dịch vụ '.$this->appointment->service->name.'.',
            ],
            self::EVENT_CHECKED_IN => [
                'title' => 'Khách hàng đã check-in',
                'message' => $this->customerName().' đã check-in cho lịch '.$this->appointment->service->name.'.',
            ],
            self::EVENT_RESCHEDULED => [
                'title' => 'Lịch hẹn đã được thay đổi',
                'message' => $this->customerName().' đã đổi lịch '.$this->appointment->service->name.' với bạn sang thời gian mới.',
            ],
            self::EVENT_CANCELLED => [
                'title' => 'Lịch hẹn đã bị huỷ',
                'message' => $this->customerName().' đã huỷ lịch '.$this->appointment->service->name.' với bạn.',
            ],
            default => $content,
        };
    }

    /** @param array{appointment_date: string, start_time: string, end_time: string} $schedule */
    private function formatSchedule(array $schedule): string
    {
        return CarbonImmutable::parse($schedule['appointment_date'])->format('d/m/Y')
            .' '.$schedule['start_time'].' - '.$schedule['end_time'];
    }

    private function actionUrl(object $notifiable): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        if ($notifiable instanceof User && $notifiable->isDoctor()) {
            return $frontendUrl.'/doctor/appointments/'.$this->appointment->id;
        }

        if ($notifiable instanceof User && $notifiable->isReceptionist()) {
            return $frontendUrl.'/receptionist/appointments/'.$this->appointment->id;
        }

        if (($this->context['audience'] ?? null) === self::AUDIENCE_ADMIN
            || $notifiable instanceof User && $notifiable->isAdmin()) {
            return $frontendUrl.'/admin/appointments/'.$this->appointment->id;
        }

        if ($this->appointment->isGuest()) {
            return $frontendUrl.'/appointment-lookup?booking_code='.urlencode((string) $this->appointment->booking_code);
        }

        return $frontendUrl.'/account/appointments/'.$this->appointment->id;
    }

    private function recipientName(object $notifiable): ?string
    {
        if (isset($notifiable->name) && is_string($notifiable->name)) {
            return $notifiable->name;
        }

        if ($notifiable instanceof AnonymousNotifiable) {
            $route = $notifiable->routeNotificationFor('mail');

            if (is_array($route)) {
                return array_values($route)[0] ?? null;
            }
        }

        return null;
    }

    private function customerName(): string
    {
        if ($this->appointment->isGuest()) {
            return $this->appointment->guest_name ?? 'Khách vãng lai';
        }

        return $this->appointment->user?->name ?? 'Khách hàng';
    }

    private function bookingReference(): string
    {
        return $this->appointment->booking_code ?? '#'.$this->appointment->id;
    }
}
