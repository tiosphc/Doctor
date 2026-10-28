@php
    $statusLabels = ['requested' => 'Chờ duyệt', 'approved' => 'Đã duyệt, chờ nhận hàng', 'rejected' => 'Đã từ chối', 'pending' => 'Đã nhận, chờ kiểm tra', 'completed' => 'Đã kiểm tra'];
    $reasonLabels = ['defective' => 'Sản phẩm bị lỗi', 'damaged_in_delivery' => 'Hư hỏng khi vận chuyển', 'wrong_product' => 'Giao sai sản phẩm', 'wrong_specification' => 'Sai quy cách', 'not_as_described' => 'Không đúng mô tả', 'other' => 'Lý do khác'];
    $orderRoute = $channel === 'retail' ? 'retail.web.orders.show' : 'dealer.web.orders.show';
@endphp
<div class="mx-auto max-w-3xl space-y-6">
    <a href="{{ route($orderRoute, $order->id) }}" class="text-sm font-semibold text-junie-navy hover:underline">← Đơn {{ $order->order_code }}</a>
    <header><h1 class="break-words text-3xl font-semibold text-junie-navy">Yêu cầu {{ $returnEntry->return_code }}</h1><p class="mt-2 text-sm text-slate-600">{{ $statusLabels[$returnEntry->status] ?? $returnEntry->status }} · Gửi lúc {{ $returnEntry->created_at?->format('d/m/Y H:i') }}</p></header>
    <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 text-sm shadow-sm">
        <p><strong>Lý do:</strong> {{ $reasonLabels[$returnEntry->reason_code] ?? $returnEntry->reason_code }}</p>
        @if ($returnEntry->note)<p class="whitespace-pre-wrap break-words"><strong>Mô tả:</strong> {{ $returnEntry->note }}</p>@endif
        @if ($returnEntry->rejection_reason)<p class="whitespace-pre-wrap break-words text-red-700"><strong>Lý do từ chối:</strong> {{ $returnEntry->rejection_reason }}</p>@endif
        <div class="divide-y divide-slate-100 border-t border-slate-200">
            @foreach ($returnEntry->items as $item)
                <p class="py-3">{{ $item->salesOrderItem?->product_name_snapshot }} · {{ $item->salesOrderItem?->sku_snapshot }} × {{ $item->quantity }}</p>
            @endforeach
        </div>
        <ol class="space-y-2 border-l-2 border-slate-200 pl-4 text-slate-600">
            <li>Đã gửi yêu cầu · {{ $returnEntry->created_at?->format('d/m/Y H:i') }}</li>
            @if ($returnEntry->approved_at)<li>Đã duyệt · {{ $returnEntry->approved_at->format('d/m/Y H:i') }}</li>@endif
            @if ($returnEntry->rejected_at)<li>Đã từ chối · {{ $returnEntry->rejected_at->format('d/m/Y H:i') }}</li>@endif
            @if ($returnEntry->received_at)<li>Đã nhận hàng trả · {{ $returnEntry->received_at->format('d/m/Y H:i') }}</li>@endif
            @if ($returnEntry->completed_at)<li>Đã kiểm tra · {{ $returnEntry->completed_at->format('d/m/Y H:i') }}</li>@endif
        </ol>
        @if ($returnEntry->status === 'completed')
            <p class="border-t border-slate-200 pt-3 font-semibold">Đã hoàn theo giao dịch hoàn tất: <x-formatted-number :value="$returnEntry->refunds->sum('amount')" currency /></p>
        @else
            <p class="border-t border-slate-200 pt-3 text-slate-600">Yêu cầu trả hàng chưa đồng nghĩa với việc đã hoàn tiền.</p>
        @endif
    </section>
</div>
