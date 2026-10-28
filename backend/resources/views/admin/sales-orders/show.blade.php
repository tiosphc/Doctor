@extends('layouts.admin')

@section('title', $order->order_code)
@section('page-title', $order->order_code)

@section('content')
<div class="space-y-6">
    <a href="{{ route('admin.web.sales.orders.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Danh sách đơn hàng</a>
    <div class="flex flex-wrap gap-3 text-sm"><a href="{{ route('admin.web.sales.returns.create', $order) }}" class="font-semibold text-junie-navy hover:underline">Tạo phiếu trả hàng</a><a href="{{ route('admin.web.sales.returns.index', ['search' => $order->order_code]) }}" class="font-semibold text-junie-navy hover:underline">Xem phiếu trả của đơn</a></div>
    <header class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">{{ ucfirst($order->sales_channel) }} · {{ $order->order_source }}</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">{{ $order->order_code }}</h1><p class="mt-2 text-sm text-slate-600">Tạo lúc {{ $order->created_at?->format('d/m/Y H:i') }} · Cập nhật {{ $order->updated_at?->format('d/m/Y H:i') }}</p></div><div class="flex flex-wrap gap-2"><x-badge>{{ $order->order_status }}</x-badge><x-badge :tone="$order->payment_status === 'paid' ? 'success' : 'gold'">{{ $order->payment_status }}</x-badge><x-badge>{{ $order->fulfillment_status }}</x-badge></div></header>

    @if ($canEditDraft)
        <a href="{{ route('admin.web.sales.orders.edit', $order) }}" class="inline-flex min-h-11 items-center rounded-xl border border-junie-navy px-4 text-sm font-semibold text-junie-navy hover:bg-slate-50">Chỉnh sửa bản nháp</a>
    @endif

    @if ($errors->any())
        <div class="space-y-2">@foreach ($errors->all() as $message)<x-alert tone="error">{{ $message }}</x-alert>@endforeach</div>
    @endif

    @if (in_array(true, $lifecycleActions, true))
        @php
            $lifecycleRoutes = [
                'confirm' => route('admin.web.sales.orders.confirm', $order),
                'reprice' => route('admin.web.sales.orders.reprice', $order),
                'cancel' => route('admin.web.sales.orders.cancel', $order),
            ];
        @endphp
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" x-data="{ active: null, submitting: false, reason: @js(old('reason', '')), routes: @js($lifecycleRoutes), keys: @js($operationKeys) }" @keydown.escape.window="if (!submitting) active = null">
            <h2 class="text-lg font-semibold text-junie-navy">Thao tác đơn hàng</h2>
            <p class="mt-1 text-sm text-slate-600">Mỗi thao tác được kiểm tra lại với trạng thái, giá và tồn kho hiện tại khi gửi.</p>
            <div class="mt-4 flex flex-wrap gap-3">
                @if ($lifecycleActions['reprice'])<button type="button" @click="active = 'reprice'" class="min-h-11 rounded-xl border border-junie-navy px-4 text-sm font-semibold text-junie-navy">Cập nhật giá</button>@endif
                @if ($lifecycleActions['confirm'])<button type="button" @click="active = 'confirm'" class="min-h-11 rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Xác nhận đơn</button>@endif
                @if ($lifecycleActions['cancel'])<button type="button" @click="active = 'cancel'" class="min-h-11 rounded-xl border border-red-200 px-4 text-sm font-semibold text-red-700">Hủy đơn</button>@endif
            </div>
            <div x-cloak x-show="active !== null" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" @click.self="if (!submitting) active = null">
                <div role="dialog" aria-modal="true" aria-labelledby="sales-order-action-title" class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl sm:p-6">
                    <h3 id="sales-order-action-title" class="text-xl font-semibold text-junie-navy" x-text="active === 'confirm' ? 'Xác nhận đơn hàng?' : active === 'reprice' ? 'Cập nhật giá đơn nháp?' : 'Hủy đơn hàng?'"></h3>
                    <p x-show="active === 'confirm'" class="mt-3 text-sm text-slate-600">Hệ thống kiểm tra lại giá, trạng thái và tồn kho. Hàng sẽ được giữ nếu đơn chưa có reservation.</p>
                    <p x-show="active === 'reprice'" class="mt-3 text-sm text-slate-600">Giá và tổng tiền đã lưu có thể thay đổi theo bảng giá Retail hiện tại. Hành động này cập nhật đơn nháp; không tự xác nhận hoặc giữ hàng.</p>
                    <p x-show="active === 'cancel'" class="mt-3 text-sm text-slate-600">Đơn được hủy và hàng đang giữ được giải phóng theo quy trình hiện có. Thao tác không tự tạo hoàn tiền.</p>
                    <form method="POST" :action="routes[active]" class="mt-5 space-y-4" @submit="submitting = true">
                        @csrf
                        <input type="hidden" name="operation_key" :value="keys[active]">
                        <input type="hidden" name="lifecycle_action" :value="active">
                        <label x-show="active === 'cancel'" class="block"><span class="text-sm font-semibold">Lý do hủy *</span><textarea name="reason" x-model="reason" :required="active === 'cancel'" maxlength="1000" rows="3" class="mt-2 w-full rounded-xl border border-slate-300 p-3 text-sm"></textarea></label>
                        <div class="flex flex-wrap justify-end gap-3"><button type="button" @click="active = null" :disabled="submitting" class="min-h-11 rounded-xl border border-slate-300 px-4 text-sm font-semibold disabled:opacity-50">Quay lại</button><button type="submit" :disabled="submitting || (active === 'cancel' && !reason.trim())" class="min-h-11 rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white disabled:opacity-50" x-text="submitting ? 'Đang xử lý...' : 'Xác nhận thao tác'"></button></div>
                    </form>
                </div>
            </div>
        </section>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Khách hàng và giao hàng</h2><dl class="mt-4 grid grid-cols-[9rem_minmax(0,1fr)] gap-3 text-sm"><dt class="text-slate-500">Người mua</dt><dd>{{ $order->buyer?->name ?? $order->dealer_name_snapshot ?? '—' }}</dd><dt class="text-slate-500">Đại lý</dt><dd>{{ $order->dealer_name_snapshot ?? '—' }}</dd><dt class="text-slate-500">Người nhận</dt><dd>{{ $order->recipient_name }}</dd><dt class="text-slate-500">Điện thoại</dt><dd>{{ $order->recipient_phone }}</dd><dt class="text-slate-500">Email</dt><dd class="break-all">{{ $order->recipient_email ?? '—' }}</dd><dt class="text-slate-500">Địa chỉ</dt><dd>{{ collect([$order->shipping_address_line1, $order->shipping_address_line2, $order->shipping_ward, $order->shipping_district, $order->shipping_city, $order->shipping_province, $order->shipping_country])->filter()->join(', ') }}</dd><dt class="text-slate-500">Ghi chú</dt><dd class="whitespace-pre-wrap">{{ $order->delivery_note ?? '—' }}</dd></dl></section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Thanh toán và kho</h2><dl class="mt-4 grid grid-cols-[9rem_minmax(0,1fr)] gap-3 text-sm"><dt class="text-slate-500">Kho xuất</dt><dd>{{ $order->warehouse?->name ?? '—' }} ({{ $order->warehouse?->code ?? '—' }})</dd><dt class="text-slate-500">Phương thức</dt><dd>{{ $order->payment_method ?? 'Chưa chọn' }}</dd><dt class="text-slate-500">Tham chiếu</dt><dd>{{ $order->external_reference ?? '—' }}</dd><dt class="text-slate-500">Tạm tính</dt><dd><x-formatted-number :value="$order->subtotal" currency /></dd><dt class="text-slate-500">Giảm giá</dt><dd><x-formatted-number :value="$order->discount_total" currency /></dd><dt class="text-slate-500">Vận chuyển</dt><dd><x-formatted-number :value="$order->shipping_total" currency /></dd><dt class="font-semibold text-junie-navy">Tổng tiền</dt><dd class="font-semibold text-junie-navy"><x-formatted-number :value="$order->grand_total" currency /></dd><dt class="text-slate-500">Đã thanh toán</dt><dd><x-formatted-number :value="$order->paid_amount" currency /></dd><dt class="text-slate-500">Còn phải thu</dt><dd><x-formatted-number :value="$order->outstanding_amount" currency /></dd><dt class="text-slate-500">Đã hoàn</dt><dd><x-formatted-number :value="$order->refunded_amount" currency /></dd></dl></section>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="border-b border-slate-200 p-5"><h2 class="text-lg font-semibold text-junie-navy">Sản phẩm trong đơn</h2><p class="mt-1 text-xs text-slate-500">Giá và tên là dữ liệu đã lưu cùng đơn hàng.</p></div><div class="overflow-x-auto"><table class="min-w-[720px] w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-600"><tr><th class="p-4">Sản phẩm / SKU</th><th class="p-4">Số lượng</th><th class="p-4">Đơn giá</th><th class="p-4">Giảm giá</th><th class="p-4 text-right">Thành tiền</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse ($order->items as $item)<tr><td class="p-4"><p class="font-semibold">{{ $item->product_name_snapshot }} · {{ $item->variant_name_snapshot }}</p><p class="font-mono text-xs text-slate-500">{{ $item->sku_snapshot }} @if ($item->is_gift)· Quà tặng @endif</p></td><td class="p-4">{{ $item->quantity }} {{ $item->unit_name_snapshot }}@if ($item->reservation)<p class="text-xs text-slate-500">Đã giữ {{ $item->reservation->original_quantity }} · Đã xuất {{ $item->reservation->consumed_quantity }} · Đã giải phóng {{ $item->reservation->released_quantity }}</p>@endif</td><td class="p-4"><x-formatted-number :value="$item->unit_price_snapshot" currency /></td><td class="p-4"><x-formatted-number :value="$item->discount_amount" currency /></td><td class="p-4 text-right font-semibold"><x-formatted-number :value="$item->line_total" currency /></td></tr>@empty<tr><td colspan="5" class="p-5 text-center text-slate-500">Đơn chưa có sản phẩm.</td></tr>@endforelse</tbody></table></div></section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-junie-navy">Kho và xuất hàng</h2>
                <p class="mt-1 text-sm text-slate-600">Kho {{ $order->warehouse?->name ?? '—' }} · {{ $order->fulfillment_status }}</p>
            </div>
            @if ($canFulfill)
                <div x-data="{ open: false, submitting: false, selected: {}, quantities: @js($fulfillSuggestions) }">
                    <button type="button" @click="open = true" class="min-h-11 rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Xuất hàng</button>
                    <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" @keydown.escape.window="if (!submitting) open = false" @click.self="if (!submitting) open = false">
                        <div role="dialog" aria-modal="true" aria-labelledby="fulfill-title" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-5 shadow-xl sm:p-6">
                            <h3 id="fulfill-title" class="text-xl font-semibold text-junie-navy">Xuất hàng cho {{ $order->order_code }}?</h3>
                            <p class="mt-2 text-sm text-slate-600">Chọn SKU và số lượng thực xuất. Hệ thống sẽ trừ tồn kho và phần hàng đã giữ theo từng dòng; có thể xuất một phần.</p>
                            <form method="POST" action="{{ route('admin.web.sales.orders.fulfill', $order) }}" class="mt-5 space-y-4" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                                @csrf
                                <input type="hidden" name="operation_key" value="{{ $operationKeys['fulfill'] }}">
                                <input type="hidden" name="lifecycle_action" value="fulfill">
                                @foreach ($order->items as $item)
                                    @if (bccomp($remainingQuantities[$item->id], '1', 3) >= 0)
                                        <div class="grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[auto_minmax(0,1fr)_8rem] sm:items-center">
                                            <input type="checkbox" x-model="selected[{{ $item->id }}]" aria-label="Chọn {{ $item->sku_snapshot }}" class="h-5 w-5 rounded border-slate-300">
                                            <div class="min-w-0 text-sm"><p class="font-semibold">{{ $item->product_name_snapshot }} · {{ $item->variant_name_snapshot }}</p><p class="break-all font-mono text-xs text-slate-500">{{ $item->sku_snapshot }} · Còn chờ xuất {{ $remainingQuantities[$item->id] }} {{ $item->unit_name_snapshot }}</p></div>
                                            <div>
                                                <input type="hidden" name="items[{{ $loop->index }}][item_id]" value="{{ $item->id }}" :disabled="!selected[{{ $item->id }}]">
                                                <label class="text-xs font-semibold text-slate-600" for="fulfill-quantity-{{ $item->id }}">Xuất lần này</label>
                                                <input id="fulfill-quantity-{{ $item->id }}" type="number" name="items[{{ $loop->index }}][quantity]" x-model="quantities[{{ $item->id }}]" min="1" max="{{ $remainingQuantities[$item->id] }}" step="1" :required="selected[{{ $item->id }}] === true" :disabled="!selected[{{ $item->id }}]" class="mt-1 w-full rounded-xl border border-slate-300 p-2 text-sm disabled:bg-slate-100">
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                                <div class="flex flex-wrap justify-end gap-3"><button type="button" @click="open = false" :disabled="submitting" class="min-h-11 rounded-xl border border-slate-300 px-4 text-sm font-semibold disabled:opacity-50">Quay lại</button><button type="submit" :disabled="submitting || !Object.values(selected).some(Boolean)" class="min-h-11 rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white disabled:opacity-50" x-text="submitting ? 'Đang xuất...' : 'Xác nhận xuất kho'"></button></div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($order->items as $item)
                <div class="rounded-xl bg-slate-50 p-3 text-sm">
                    <p class="break-all font-semibold">{{ $item->sku_snapshot }} @if ($item->is_gift)· Quà tặng @endif</p>
                    <p class="mt-2 text-slate-600">Đặt {{ $item->quantity }} · Giữ {{ $item->reservation?->original_quantity ?? '0' }}</p>
                    <p class="text-slate-600">Đã xuất {{ $item->reservation?->consumed_quantity ?? '0' }} · Còn chờ {{ $remainingQuantities[$item->id] }}</p>
                    @if ($item->reservation && bccomp((string) $item->reservation->released_quantity, '0', 3) > 0)<p class="text-slate-600">Đã giải phóng {{ $item->reservation->released_quantity }}</p>@endif
                </div>
            @endforeach
        </div>
        @if ($advanceTarget)
            @php $advanceLabels = ['preparing' => 'Chuẩn bị hàng', 'shipping' => 'Xuất kho và giao hàng', 'delivered' => 'Xác nhận đã giao']; @endphp
            <div class="mt-5 border-t border-slate-200 pt-4" x-data="{ open: false, submitting: false }">
                <p class="text-sm text-slate-600">Đơn Retail từ giỏ hàng đi theo quy trình giao hàng trọn đơn hiện có.</p>
                <button type="button" @click="open = true" class="mt-3 min-h-11 rounded-xl border border-junie-navy px-4 text-sm font-semibold text-junie-navy">{{ $advanceLabels[$advanceTarget] }}</button>
                <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" @keydown.escape.window="if (!submitting) open = false" @click.self="if (!submitting) open = false">
                    <div role="dialog" aria-modal="true" aria-labelledby="advance-title" class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl sm:p-6">
                        <h3 id="advance-title" class="text-xl font-semibold text-junie-navy">{{ $advanceLabels[$advanceTarget] }}?</h3>
                        <p class="mt-2 text-sm text-slate-600">@if ($advanceTarget === 'shipping') Thao tác sẽ xuất toàn bộ hàng đã giữ và ghi nhận xuất kho. @elseif ($advanceTarget === 'delivered') Hệ thống sẽ kiểm tra điều kiện thanh toán trước khi hoàn tất đơn. @else Trạng thái đơn sẽ được cập nhật theo quy trình hiện có. @endif</p>
                        <form method="POST" action="{{ route('admin.web.sales.orders.advance', $order) }}" class="mt-5" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                            @csrf
                            <input type="hidden" name="operation_key" value="{{ $operationKeys['advance'] }}">
                            <input type="hidden" name="lifecycle_action" value="advance">
                            <input type="hidden" name="target" value="{{ $advanceTarget }}">
                            <div class="flex flex-wrap justify-end gap-3"><button type="button" @click="open = false" :disabled="submitting" class="min-h-11 rounded-xl border border-slate-300 px-4 text-sm font-semibold disabled:opacity-50">Quay lại</button><button type="submit" :disabled="submitting" class="min-h-11 rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white disabled:opacity-50" x-text="submitting ? 'Đang xử lý...' : 'Xác nhận'"></button></div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold text-junie-navy">Thanh toán và đối chiếu</h2><p class="mt-1 text-sm text-slate-600">Số đã nhận được tính từ các khoản phân bổ thanh toán đã settled.</p></div><x-badge :tone="$derivedPaymentStatus === 'paid' ? 'success' : 'gold'">{{ $derivedPaymentStatus }}</x-badge></div>
        <div class="mt-4 grid gap-3 text-sm sm:grid-cols-3"><div class="rounded-xl bg-slate-50 p-3">Tổng đơn<br><strong><x-formatted-number :value="$order->grand_total" currency /></strong></div><div class="rounded-xl bg-slate-50 p-3">Đã nhận<br><strong><x-formatted-number :value="$order->paid_amount" currency /></strong></div><div class="rounded-xl bg-slate-50 p-3">Còn phải thu<br><strong><x-formatted-number :value="$order->outstanding_amount" currency /></strong></div></div>
        @if ($order->payment_status !== $derivedPaymentStatus)<x-alert tone="error" class="mt-4">Trạng thái thanh toán đã lưu khác số liệu từ các khoản settled. Cần kiểm tra bằng quy trình đối soát hiện có trước khi xử lý thêm.</x-alert>@endif
        @if ($order->sales_channel === 'dealer' && $order->payment_method === 'dealer_wallet')<p class="mt-3 text-sm text-slate-600">Thanh toán Ví đại lý được xử lý qua luồng ví hiện có; trang này chỉ hiển thị lịch sử.</p>@endif
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3"><h3 class="font-semibold text-junie-navy">Lịch sử thanh toán</h3>
            @if ($canRecordPayment)
                <div x-data="{ open: false, submitting: false }">
                    <button type="button" @click="open = true" class="min-h-11 rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Ghi nhận đã nhận tiền</button>
                    <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" @keydown.escape.window="if (!submitting) open = false" @click.self="if (!submitting) open = false">
                        <div role="dialog" aria-modal="true" aria-labelledby="payment-title" class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl sm:p-6">
                            <h3 id="payment-title" class="text-xl font-semibold text-junie-navy">Xác nhận tiền đã nhận?</h3>
                            <p class="mt-2 text-sm text-slate-600">Còn phải thu <x-formatted-number :value="$order->outstanding_amount" currency />. Chỉ ghi nhận sau khi đã nhận tiền; xuất kho và giao hàng không tự thu COD hoặc xác nhận chuyển khoản.</p>
                            <form method="POST" action="{{ route('admin.web.sales.orders.payments.store', $order) }}" class="mt-5 space-y-4" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                                @csrf
                                <input type="hidden" name="operation_key" value="{{ $operationKeys['payment'] }}">
                                <input type="hidden" name="lifecycle_action" value="payment">
                                <label class="block text-sm font-semibold">Số tiền đã nhận *<input name="amount" value="{{ old('amount', $order->outstanding_amount) }}" inputmode="decimal" required maxlength="19" class="mt-2 w-full rounded-xl border border-slate-300 p-3 text-sm" placeholder="{{ $order->outstanding_amount }}"></label>
                                <label class="block text-sm font-semibold">Phương thức *<select name="payment_method" required class="mt-2 w-full rounded-xl border border-slate-300 p-3 text-sm"><option value="bank_transfer" @selected(old('payment_method') === 'bank_transfer')>Chuyển khoản</option><option value="cash" @selected(old('payment_method') === 'cash')>Tiền mặt</option><option value="other_manual" @selected(old('payment_method') === 'other_manual')>Thủ công khác</option></select></label>
                                <label class="block text-sm font-semibold">Mã tham chiếu<input name="external_reference" value="{{ old('external_reference') }}" maxlength="255" class="mt-2 w-full rounded-xl border border-slate-300 p-3 text-sm"></label>
                                <label class="block text-sm font-semibold">Ghi chú<textarea name="note" maxlength="1000" rows="3" class="mt-2 w-full rounded-xl border border-slate-300 p-3 text-sm">{{ old('note') }}</textarea></label>
                                <div class="flex flex-wrap justify-end gap-3"><button type="button" @click="open = false" :disabled="submitting" class="min-h-11 rounded-xl border border-slate-300 px-4 text-sm font-semibold disabled:opacity-50">Quay lại</button><button type="submit" :disabled="submitting" class="min-h-11 rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white disabled:opacity-50" x-text="submitting ? 'Đang ghi nhận...' : 'Xác nhận thanh toán'"></button></div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        </div>
        <ol class="mt-3 divide-y divide-slate-100">
            @forelse ($paymentAllocations as $allocation)
                <li class="py-4 text-sm"><div class="flex flex-wrap justify-between gap-2"><strong class="font-mono">{{ $allocation->payment->payment_code }}</strong><x-badge :tone="$allocation->payment->status === 'settled' ? 'success' : 'gold'">{{ $allocation->payment->status }}</x-badge></div><p class="mt-1">Phân bổ cho đơn: <x-formatted-number :value="$allocation->allocated_amount" currency /> · {{ $allocation->payment->payment_method }}</p><p class="text-slate-600">{{ $allocation->payment->settled_at?->format('d/m/Y H:i') ?? $allocation->payment->created_at?->format('d/m/Y H:i') }} · {{ $allocation->payment->recorder?->name ?? 'Hệ thống' }}</p>@if ($allocation->payment->external_reference)<p class="break-all text-slate-600">Tham chiếu: {{ $allocation->payment->external_reference }}</p>@endif @if ($allocation->payment->note)<p class="whitespace-pre-wrap text-slate-600">{{ $allocation->payment->note }}</p>@endif</li>
            @empty
                <li class="py-4 text-sm text-slate-500">Chưa có khoản thanh toán nào được phân bổ cho đơn.</li>
            @endforelse
        </ol>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Lịch sử đơn hàng</h2><ol class="mt-4 divide-y divide-slate-100">@forelse ($order->histories as $history)<li class="py-4 text-sm"><div class="flex flex-wrap justify-between gap-2"><p class="font-semibold">{{ $history->event_type }}</p><time class="text-slate-500">{{ $history->created_at?->format('d/m/Y H:i') }}</time></div><p class="mt-1 text-slate-600">{{ $history->actor?->name ?? 'Hệ thống' }} @if ($history->from_status || $history->to_status)· {{ $history->from_status ?? '—' }} → {{ $history->to_status ?? '—' }}@endif</p>@if ($history->note)<p class="mt-1 whitespace-pre-wrap text-slate-600">{{ $history->note }}</p>@endif</li>@empty<li class="py-4 text-sm text-slate-500">Chưa có lịch sử.</li>@endforelse</ol></section>
</div>
@endsection
