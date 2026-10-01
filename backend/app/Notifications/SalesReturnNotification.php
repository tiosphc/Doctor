<?php

namespace App\Notifications;

use App\Models\SalesReturn;
use Illuminate\Notifications\Notification;

class SalesReturnNotification extends Notification
{
    public function __construct(
        public readonly SalesReturn $salesReturn,
        public readonly string $event,
        public readonly bool $forAdmin = false,
        public readonly ?string $amount = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $title = match ($this->event) {
            'requested' => 'Yêu cầu trả hàng mới',
            'approved' => 'Yêu cầu trả hàng đã được chấp nhận',
            'rejected' => 'Yêu cầu trả hàng đã bị từ chối',
            'received' => 'Đã nhận hàng trả',
            'completed' => 'Đã kiểm tra hàng trả',
            'refunded' => 'Đã hoàn tiền',
            default => 'Cập nhật yêu cầu trả hàng',
        };
        $message = match ($this->event) {
            'requested' => "Phiếu {$this->salesReturn->return_code} đang chờ duyệt.",
            'approved' => 'Yêu cầu trả hàng của bạn đã được chấp nhận. Vui lòng gửi/trả hàng theo hướng dẫn.',
            'rejected' => 'Yêu cầu trả hàng đã bị từ chối. '.$this->salesReturn->rejection_reason,
            'received' => 'Đã xác nhận nhận hàng trả.',
            'completed' => 'Hàng trả đã được kiểm tra. Hoàn tiền được xử lý riêng.',
            'refunded' => 'Đã hoàn tiền '.number_format((float) $this->amount, 0, ',', '.').' ₫.',
            default => "Phiếu {$this->salesReturn->return_code} đã được cập nhật.",
        };
        $path = $this->forAdmin ? '/admin/sales-orders/'.$this->salesReturn->sales_order_id
            : ($this->salesReturn->request_source === 'dealer' ? '/dealer/orders/' : '/my-orders/').$this->salesReturn->sales_order_id;

        return [
            'event' => 'sales_return_'.$this->event,
            'title' => $title,
            'message' => $message,
            'action_url' => rtrim((string) config('app.frontend_url'), '/').$path,
            'status' => $this->salesReturn->status,
            'source' => 'sales_return',
            'audience' => $this->forAdmin ? 'admin' : 'customer',
            'data' => [
                'sales_return_id' => $this->salesReturn->id,
                'sales_order_id' => $this->salesReturn->sales_order_id,
                'return_code' => $this->salesReturn->return_code,
            ],
        ];
    }
}
