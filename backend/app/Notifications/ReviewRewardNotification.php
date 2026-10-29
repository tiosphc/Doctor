<?php

namespace App\Notifications;

use App\Models\Voucher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

class ReviewRewardNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public readonly Voucher $voucher) {}

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
        $percent = (int) $this->voucher->value;

        return [
            'event' => 'review_reward_issued',
            'title' => 'Cảm ơn bạn đã đánh giá',
            'message' => "Bạn đã nhận voucher giảm {$percent}% cho lần đặt lịch tiếp theo.",
            'appointment_id' => null,
            'action_url' => rtrim((string) config('app.frontend_url'), '/').'/account/vouchers',
            'status' => $this->voucher->status,
        ];
    }
}
