<?php

namespace App\Notifications;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

class DoctorAccountInvitation extends Notification implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $query = http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ], encoding_type: PHP_QUERY_RFC3986);
        $expiration = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Thiết lập tài khoản bác sĩ JUNIE')
            ->greeting('Xin chào '.$notifiable->name.',')
            ->line('Tài khoản bác sĩ của bạn trên hệ thống JUNIE đã được tạo.')
            ->line('Email đăng nhập: '.$notifiable->email)
            ->line('Vui lòng thiết lập mật khẩu trước khi sử dụng tài khoản.')
            ->action('Thiết lập mật khẩu', $frontendUrl.'/setup-password?'.$query)
            ->line("Liên kết này sẽ hết hạn sau {$expiration} phút.")
            ->line('Nếu bạn không mong đợi email này, vui lòng liên hệ quản trị viên.');
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $channel === 'mail'
            && $notifiable instanceof User
            && $notifiable->isDoctor()
            && $notifiable->must_change_password
            && $notifiable->doctorProfile?->status === Doctor::STATUS_ACTIVE
            && Password::broker()->tokenExists($notifiable, $this->token);
    }
}
