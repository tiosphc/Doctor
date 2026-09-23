<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

class ReviewInvitationNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public readonly Appointment $appointment) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'review_invitation',
            'title' => 'Buổi hẹn của bạn đã hoàn thành',
            'message' => 'Chia sẻ trải nghiệm để nhận ưu đãi cho lần đặt lịch tiếp theo.',
            'appointment_id' => $this->appointment->id,
            'action_url' => rtrim((string) config('app.frontend_url'), '/').'/account/appointments/'.$this->appointment->id,
            'status' => $this->appointment->status,
        ];
    }
}
