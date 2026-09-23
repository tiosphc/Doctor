<?php

namespace App\Notifications;

use App\Models\Voucher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

class LoyaltyMilestoneRewardNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly Voucher $voucher,
        public readonly int $milestone,
    ) {}

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
            'event' => 'loyalty_milestone_reward',
            'title' => "Bạn vừa nhận Voucher {$percent}%",
            'message' => "Cảm ơn bạn đã đồng hành cùng phòng khám. Bạn đã hoàn thành {$this->milestone} lần sử dụng dịch vụ và nhận được Voucher giảm {$percent}% cho lần đặt lịch tiếp theo.",
            'appointment_id' => null,
            'action_url' => rtrim((string) config('app.frontend_url'), '/').'/account/vouchers',
            'status' => $this->voucher->status,
            'source' => Voucher::SOURCE_LOYALTY_MILESTONE,
            'data' => [
                'voucher_id' => $this->voucher->id,
                'milestone' => $this->milestone,
                'reward_value' => $percent,
            ],
        ];
    }
}
