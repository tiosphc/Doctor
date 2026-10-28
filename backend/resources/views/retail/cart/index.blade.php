@extends('layouts.customer')

@section('title', 'Giỏ hàng')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div><p class="text-xs font-semibold uppercase tracking-widest text-junie-gold">Junie Retail</p>
            <h1 class="mt-2 text-3xl font-semibold text-junie-navy">Giỏ hàng</h1></div>
        <a href="{{ route('retail.web.shop.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">Tiếp tục mua sắm →</a>
    </div>

    @if ($cart['item_count'] === 0)
        <div class="space-y-4"><x-empty-state title="Giỏ hàng của bạn đang trống." />
            <a href="{{ route('retail.web.shop.index') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-5 text-sm font-semibold text-white">Chọn sản phẩm</a></div>
    @else
        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div class="space-y-4">
                @foreach ($cart['items'] as $line)
                    <article class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[6rem_minmax(0,1fr)]">
                        <a href="{{ route('retail.web.shop.show', $line['product_slug']) }}" class="aspect-square overflow-hidden rounded-xl bg-slate-100">
                            @if ($line['image_url'])<img src="{{ $line['image_url'] }}" alt="{{ $line['product_name'] }}" class="h-full w-full object-cover">@endif
                        </a>
                        <div class="space-y-2">
                            <a href="{{ route('retail.web.shop.show', $line['product_slug']) }}" class="font-semibold text-junie-navy hover:underline">{{ $line['product_name'] }}</a>
                            <p class="text-sm text-slate-600">{{ $line['variant_name'] }} · {{ $line['sku'] }} · {{ $line['unit_name'] }}</p>
                            <p class="text-sm">Đơn giá: @if ($line['retail_price'])<x-formatted-number :value="$line['retail_price']['unit_price']" currency />@else Chưa có giá Retail @endif</p>
                            @if ($line['promotion_discount_amount'] !== '0.00')
                                <p class="text-sm text-emerald-700">Ưu đãi dòng: −<x-formatted-number :value="$line['promotion_discount_amount']" currency /></p>
                            @endif
                            @if ($line['errors'] !== [])
                                <p class="text-sm text-red-700" role="alert">Cần kiểm tra: {{ implode(', ', $line['errors']) }}</p>
                            @endif
                            <p class="font-semibold">Thành tiền: <x-formatted-number :value="$line['discounted_line_total'] ?? $line['line_total']" currency /></p>
                            <div class="flex flex-wrap items-end gap-2">
                                <form method="POST" action="{{ route('retail.web.cart.items.update', $line['id']) }}" class="flex items-end gap-2" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                                    @csrf @method('PATCH')
                                    <label class="text-xs font-medium">Số lượng
                                        <input name="quantity" type="number" min="1" step="1" value="{{ (int) $line['quantity'] }}" required class="mt-1 block min-h-11 w-24 rounded-xl border border-slate-300 px-3 text-sm">
                                    </label>
                                    <x-button type="submit" variant="secondary" x-bind:disabled="submitting">Cập nhật</x-button>
                                </form>
                                <form method="POST" action="{{ route('retail.web.cart.items.remove', $line['id']) }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                                    @csrf @method('DELETE')
                                    <x-button type="submit" variant="danger" x-bind:disabled="submitting">Xóa</x-button>
                                </form>
                            </div>
                        </div>
                    </article>
                @endforeach
                @if ($cart['gift_item'])
                    <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm">
                        <p class="font-semibold">Quà tặng · {{ $cart['gift_item']['product_name'] }}</p>
                        <p>{{ $cart['gift_item']['variant_name'] }} × {{ $cart['gift_item']['quantity'] }} · 0 ₫</p>
                    </div>
                @endif
                @if ($cart['gift_unavailable_reason'])
                    <x-alert tone="error">Đơn chưa đủ tồn kho cho hàng mua và quà tặng; ưu đãi quà chưa được áp dụng.</x-alert>
                @endif
                <form method="POST" action="{{ route('retail.web.cart.clear') }}" x-data="{ submitting: false }" @submit="if (submitting || !confirm('Xóa toàn bộ giỏ hàng?')) { $event.preventDefault(); return; } submitting = true">
                    @csrf @method('DELETE')
                    <x-button type="submit" variant="secondary" x-bind:disabled="submitting">Xóa giỏ hàng</x-button>
                </form>
            </div>
            <aside class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:sticky lg:top-6">
                <h2 class="text-xl font-semibold text-junie-navy">Tóm tắt</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-2"><dt>Tạm tính</dt><dd><x-formatted-number :value="$cart['subtotal']" currency /></dd></div>
                    <div class="flex justify-between gap-2"><dt>Giảm giá</dt><dd>−<x-formatted-number :value="$cart['discount_total']" currency /></dd></div>
                    <div class="flex justify-between gap-2 border-t border-slate-200 pt-3 text-base font-semibold"><dt>Tổng cộng</dt><dd><x-formatted-number :value="$cart['grand_total']" currency /></dd></div>
                </dl>
                @foreach ($cart['promotions'] as $promotion)
                    <p class="text-sm text-emerald-700">Ưu đãi {{ $promotion['name'] }}: −<x-formatted-number :value="$promotion['discount_amount']" currency /></p>
                @endforeach
                @if ($cart['voucher'])
                    <p class="text-sm text-emerald-700">Voucher {{ $cart['voucher']['code'] }}: −<x-formatted-number :value="$cart['voucher']['discount_amount']" currency /></p>
                @endif
                <form method="POST" action="{{ route('retail.web.cart.voucher') }}" class="space-y-2" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                    @csrf @method('PUT')
                    <label for="voucher-code" class="text-sm font-medium">Voucher Retail</label>
                    <div class="flex gap-2"><input id="voucher-code" name="code" value="{{ old('code', $cart['voucher_code']) }}" maxlength="32" placeholder="Mã voucher" class="min-h-11 min-w-0 flex-1 rounded-xl border border-slate-300 px-3 text-sm"><x-button type="submit" variant="secondary" x-bind:disabled="submitting">Áp dụng</x-button></div>
                    @if ($cart['voucher_error'])<p class="text-sm text-red-700">Voucher cần kiểm tra: {{ $cart['voucher_error'] }}</p>@endif
                    <p class="text-xs text-slate-500">Để trống rồi bấm Áp dụng để bỏ voucher.</p>
                </form>
                @if ($cart['can_checkout'])
                    <a href="{{ route('retail.web.checkout.create') }}" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Tiến hành thanh toán</a>
                @else
                    <p class="text-sm font-medium text-red-700">Giỏ hàng chưa thể thanh toán. Vui lòng kiểm tra SKU, tồn kho, giá hoặc voucher.</p>
                @endif
            </aside>
        </div>
    @endif
@endsection
