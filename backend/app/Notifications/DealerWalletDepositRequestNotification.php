<?php

namespace App\Notifications;

use App\Models\DealerWalletDepositRequest;
use Illuminate\Notifications\Notification;

class DealerWalletDepositRequestNotification extends Notification
{
    public function __construct(public readonly DealerWalletDepositRequest $depositRequest) {}

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
            'event' => 'dealer_wallet_deposit_requested',
            'title' => 'Yêu cầu nạp tiền đại lý mới',
            'message' => 'Có yêu cầu nạp tiền mới từ '.$this->depositRequest->dealerAccount->legal_name.' - '.number_format((float) $this->depositRequest->amount, 0, ',', '.').' ₫',
            'action_url' => rtrim((string) config('app.frontend_url'), '/').'/admin/dealer-wallet-top-ups?request='.$this->depositRequest->id,
            'status' => 'pending',
            'source' => 'dealer_wallet_deposit_request',
            'audience' => 'admin',
            'data' => ['request_id' => $this->depositRequest->id, 'request_code' => $this->depositRequest->request_code],
        ];
    }
}
