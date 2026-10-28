@extends('layouts.customer')

@section('title', 'Thanh toán')

@section('content')
    <a href="{{ route('retail.web.cart.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Giỏ hàng</a>
    <div class="mt-4 mb-6"><p class="text-xs font-semibold uppercase tracking-widest text-junie-gold">Junie Retail</p>
        <h1 class="mt-2 text-3xl font-semibold text-junie-navy">Xác nhận đặt hàng</h1>
        <p class="mt-2 text-sm text-slate-600">Giá, ưu đãi, voucher và tồn kho được kiểm tra lại khi bạn xác nhận.</p>
    </div>
    @unless ($review['can_checkout'])
        <x-alert tone="error">Giỏ hàng chưa sẵn sàng. Vui lòng kiểm tra SKU, tồn kho hoặc voucher rồi xem lại.</x-alert>
        <a href="{{ route('retail.web.cart.index') }}" class="mt-4 inline-flex min-h-11 items-center text-sm font-semibold text-junie-navy hover:underline">Kiểm tra giỏ hàng →</a>
    @else
        <form method="POST" action="{{ route('retail.web.checkout.store') }}" class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]" x-data="{ submitting: false }" @submit="if (submitting || !confirm('Xác nhận đặt đơn trị giá {{ number_format((float) $review['grand_total'], 0, ',', '.') }} ₫? Thanh toán chưa được xác nhận tại bước này.')) { $event.preventDefault(); return; } submitting = true">
            @csrf
            <input type="hidden" name="checkout_operation_key" value="{{ $operationKey }}">
            <input type="hidden" name="checkout_review_fingerprint" value="{{ $review['review_fingerprint'] }}">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-xl font-semibold text-junie-navy">Người nhận và giao hàng</h2>
                <p class="mt-2 text-sm text-slate-600">Người nhận có thể khác chủ tài khoản. Thông tin được lưu vào đơn hàng này.</p>
                @php($defaults = $review['recipient_defaults'])
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-medium">Người nhận *<input name="recipient_name" value="{{ old('recipient_name', $defaults['recipient_name']) }}" required maxlength="255" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                    <label class="text-sm font-medium">Số điện thoại *<input name="recipient_phone" value="{{ old('recipient_phone', $defaults['recipient_phone']) }}" required maxlength="50" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                    <label class="text-sm font-medium sm:col-span-2">Email<input name="recipient_email" type="email" value="{{ old('recipient_email', $defaults['recipient_email']) }}" maxlength="255" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                    <label class="text-sm font-medium sm:col-span-2">Địa chỉ *<input name="shipping_address_line1" value="{{ old('shipping_address_line1') }}" required maxlength="255" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                    <label class="text-sm font-medium sm:col-span-2">Địa chỉ bổ sung<input name="shipping_address_line2" value="{{ old('shipping_address_line2') }}" maxlength="255" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                    <label class="text-sm font-medium">Quận / huyện *<input name="shipping_district" value="{{ old('shipping_district') }}" required maxlength="255" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                    <label class="text-sm font-medium">Thành phố *<input name="shipping_city" value="{{ old('shipping_city') }}" required maxlength="255" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                    <label class="text-sm font-medium">Tỉnh / thành *<input name="shipping_province" value="{{ old('shipping_province') }}" required maxlength="255" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                    <label class="text-sm font-medium">Quốc gia *<input name="shipping_country" value="{{ old('shipping_country', 'VN') }}" required maxlength="255" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                    <label class="text-sm font-medium">Mã bưu chính<input name="shipping_postal_code" value="{{ old('shipping_postal_code') }}" maxlength="30" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                    <label class="text-sm font-medium sm:col-span-2">Ghi chú giao hàng<textarea name="delivery_note" rows="3" maxlength="2000" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">{{ old('delivery_note') }}</textarea></label>
                </div>
                <label class="mt-5 block text-sm font-medium">Phương thức thanh toán *
                    <select name="payment_method" required class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
                        <option value="cod" @selected(old('payment_method', 'cod') === 'cod')>Thanh toán khi nhận hàng (COD)</option>
                        <option value="bank_transfer" @selected(old('payment_method') === 'bank_transfer')>Chuyển khoản ngân hàng</option>
                    </select>
                </label>
                <p class="mt-2 text-xs text-slate-600">Chọn chuyển khoản không xác nhận đã thanh toán; trạng thái được cập nhật theo nghiệp vụ hiện có.</p>
            </section>
            <aside class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:sticky lg:top-6">
                <h2 class="text-xl font-semibold text-junie-navy">Kiểm tra đơn</h2>
                <p class="text-xs text-slate-500">Kho bán hàng: {{ $review['warehouse']['name'] ?? '—' }}</p>
                <div class="divide-y divide-slate-100">
                    @foreach ($review['items'] as $line)
                        <div class="flex justify-between gap-3 py-3 text-sm"><span>{{ $line['product_name'] }}<span class="block text-xs text-slate-500">{{ $line['variant_name'] }} × {{ $line['quantity'] }}</span></span><strong><x-formatted-number :value="$line['discounted_line_total'] ?? $line['line_total']" currency /></strong></div>
                    @endforeach
                    @if ($review['gift_item'])
                        <div class="flex justify-between gap-3 py-3 text-sm"><span>Quà tặng · {{ $review['gift_item']['product_name'] }} × {{ $review['gift_item']['quantity'] }}</span><strong>0 ₫</strong></div>
                    @endif
                </div>
                @foreach ($review['promotions'] as $promotion)
                    <p class="flex justify-between gap-2 text-sm text-emerald-700"><span>Ưu đãi {{ $promotion['name'] }}</span><span>−<x-formatted-number :value="$promotion['discount_amount']" currency /></span></p>
                @endforeach
                @if ($review['voucher'])
                    <p class="flex justify-between gap-2 text-sm text-emerald-700"><span>Voucher {{ $review['voucher']['code'] }}</span><span>−<x-formatted-number :value="$review['voucher']['discount_amount']" currency /></span></p>
                @endif
                <dl class="space-y-2 border-t border-slate-200 pt-4 text-sm">
                    <div class="flex justify-between gap-2"><dt>Tạm tính</dt><dd><x-formatted-number :value="$review['subtotal']" currency /></dd></div>
                    <div class="flex justify-between gap-2"><dt>Giảm giá</dt><dd>−<x-formatted-number :value="$review['discount_total']" currency /></dd></div>
                    <div class="flex justify-between gap-2 text-lg font-semibold"><dt>Tổng cộng</dt><dd><x-formatted-number :value="$review['grand_total']" currency /></dd></div>
                </dl>
                <p class="text-xs text-slate-600">{{ $review['gift_item'] ? 'Quà tặng và hàng mua được giữ theo quy trình đặt hàng hiện có.' : 'Đơn chờ xác nhận; tồn kho được giữ theo quy trình xác nhận hiện có.' }}</p>
                <x-button type="submit" class="w-full" x-bind:disabled="submitting">Xác nhận đặt hàng</x-button>
            </aside>
        </form>
    @endunless
@endsection
