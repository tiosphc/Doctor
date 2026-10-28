@php
    $reasonLabels = [
        'defective' => 'Sản phẩm bị lỗi',
        'damaged_in_delivery' => 'Hư hỏng khi vận chuyển',
        'wrong_product' => 'Giao sai sản phẩm',
        'wrong_specification' => 'Sai quy cách',
        'not_as_described' => 'Không đúng mô tả',
        'other' => 'Lý do khác',
    ];
    $ineligibleMessages = [
        'ORDER_CANCELLED' => 'Đơn hàng đã hủy.',
        'ORDER_NOT_DELIVERED' => 'Có thể yêu cầu trả sau khi đơn đã giao đủ.',
        'RETURN_WINDOW_EXPIRED' => 'Đã hết thời hạn yêu cầu trả hàng.',
        'NOTHING_RETURNABLE' => 'Không còn sản phẩm có thể yêu cầu trả.',
    ];
    $orderRoute = $channel === 'retail' ? 'retail.web.orders.show' : 'dealer.web.orders.show';
    $storeRoute = $channel === 'retail' ? 'retail.web.orders.returns.store' : 'dealer.web.orders.returns.store';
    $availableItems = collect($returnableItems)->filter(fn (array $item): bool => bccomp($item['returnable_quantity'], '1', 3) >= 0)->values();
@endphp
<div class="mx-auto max-w-3xl space-y-6">
    <a href="{{ route($orderRoute, $order->id) }}" class="text-sm font-semibold text-junie-navy hover:underline">← Đơn {{ $order->order_code }}</a>
    <header><h1 class="text-3xl font-semibold text-junie-navy">Yêu cầu trả hàng</h1><p class="mt-2 text-sm text-slate-600">Đơn {{ $order->order_code }} · {{ $order->order_status }}. Yêu cầu sẽ chờ nhân viên duyệt; chưa nhập kho hoặc hoàn tiền.</p></header>
    @if ($eligibility['return_deadline'])
        <p class="text-sm text-slate-600">Hạn gửi yêu cầu: {{ \Carbon\Carbon::parse($eligibility['return_deadline'])->format('d/m/Y H:i') }}.</p>
    @endif
    @if ($errors->any())<div class="space-y-2" role="alert">@foreach ($errors->all() as $message)<x-alert tone="error">{{ $message }}</x-alert>@endforeach</div>@endif
    @if (! $eligibility['return_eligible'] || $availableItems->isEmpty())
        <x-empty-state :title="$ineligibleMessages[$eligibility['return_ineligible_reason']] ?? 'Không còn số lượng nguyên đủ điều kiện để trả.'" />
    @else
        <form method="POST" action="{{ route($storeRoute, $order->id) }}" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" x-data="{ submitting: false, reason: @js(old('reason_code', '')) }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
            @csrf
            <input type="hidden" name="operation_key" value="{{ $operationKey }}">
            <fieldset class="space-y-3"><legend class="text-sm font-semibold">Chọn một hoặc nhiều sản phẩm và số lượng cần trả</legend>
                @foreach ($availableItems as $item)
                    @php $selectedRow = collect(old('items', []))->firstWhere('item_id', $item['item_id']); @endphp
                    <div class="grid min-w-0 gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-[minmax(0,1fr)_7rem]" x-data="{ selected: @js($selectedRow !== null) }">
                        <label class="flex min-w-0 items-start gap-3"><input type="checkbox" x-model="selected" class="mt-1 size-4"><span class="min-w-0"><strong class="break-words">{{ $item['product_name'] }}</strong><span class="block break-words text-xs text-slate-600">SKU {{ $item['sku'] }} · Đã giao {{ $item['fulfilled_quantity'] }} · Đã trả {{ $item['returned_quantity'] }} · Đang xử lý {{ $item['pending_quantity'] }} · Còn được trả {{ $item['returnable_quantity'] }} {{ $item['unit_code'] }}</span></span></label>
                        <label><span class="mb-1 block text-sm font-medium">Số lượng</span><input type="number" name="items[{{ $loop->index }}][quantity]" value="{{ $selectedRow['quantity'] ?? '1' }}" min="1" max="{{ (int) $item['returnable_quantity'] }}" step="1" :disabled="!selected" required class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                        <input type="hidden" name="items[{{ $loop->index }}][item_id]" value="{{ $item['item_id'] }}" :disabled="!selected">
                    </div>
                @endforeach
                @error('items')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            </fieldset>
            <label class="block text-sm font-medium">Lý do *<select name="reason_code" required x-model="reason" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option value="">Chọn lý do</option>@foreach ($reasonLabels as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></label>
            <label class="block text-sm font-medium">Mô tả <span x-text="reason === 'other' ? '*' : '(không bắt buộc)'"></span><textarea name="note" rows="3" maxlength="2000" :required="reason === 'other'" class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2">{{ old('note') }}</textarea></label>
            <button type="submit" :disabled="submitting" class="min-h-11 rounded-xl bg-junie-navy px-5 text-sm font-semibold text-white disabled:opacity-60" x-text="submitting ? 'Đang gửi...' : 'Gửi yêu cầu'"></button>
        </form>
    @endif
</div>
