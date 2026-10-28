@extends('layouts.customer')

@section('title', $order->order_code)

@section('content')
    <a href="{{ route('retail.web.orders.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Đơn hàng của tôi</a>
    <header class="mt-5 mb-6 flex flex-wrap items-start justify-between gap-4"><div>
        <p class="text-xs font-semibold uppercase tracking-widest text-junie-gold">Đơn hàng Retail</p>
        <h1 class="mt-2 text-3xl font-semibold text-junie-navy">{{ $order->order_code }}</h1>
        <p class="mt-2 text-sm text-slate-600">Đặt lúc {{ $order->created_at?->format('d/m/Y H:i') }}</p>
    </div><div class="flex flex-wrap gap-2"><x-badge>{{ $order->order_status }}</x-badge><x-badge :tone="$order->payment_status === 'paid' ? 'success' : 'gold'">{{ $order->payment_status }}</x-badge><x-badge>{{ $order->fulfillment_status }}</x-badge></div></header>
    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Sản phẩm đã đặt</h2>
                <div class="mt-3 divide-y divide-slate-100">
                    @foreach ($order->items as $item)
                        <div class="grid gap-3 py-4 text-sm sm:grid-cols-[minmax(0,1fr)_auto]"><div>
                            <p class="font-semibold">{{ $item->product_name_snapshot }} @if ($item->is_gift) <x-badge tone="gold">Quà tặng</x-badge> @endif</p>
                            <p class="mt-1 text-slate-600">{{ $item->variant_name_snapshot }} · {{ $item->sku_snapshot }}</p>
                            <p class="mt-1 text-slate-600">{{ $item->quantity }} {{ $item->unit_name_snapshot }} × <x-formatted-number :value="$item->unit_price_snapshot" currency /></p>
                            @if (bccomp($item->discount_amount, '0', 2) > 0)
                                <p class="mt-1 text-emerald-700">Giảm: −<x-formatted-number :value="$item->discount_amount" currency /></p>
                            @endif
                        </div><strong><x-formatted-number :value="$item->line_total" currency /></strong></div>
                    @endforeach
                </div>
            </section>
            @include('components.return-request-summary')
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Giao hàng</h2>
                <dl class="mt-4 grid grid-cols-[7rem_minmax(0,1fr)] gap-2 text-sm"><dt class="text-slate-500">Người nhận</dt><dd>{{ $order->recipient_name }} · {{ $order->recipient_phone }}</dd>
                    <dt class="text-slate-500">Địa chỉ</dt><dd>{{ $order->shipping_address_line1 }} {{ $order->shipping_address_line2 }}, {{ $order->shipping_district }}, {{ $order->shipping_city }}, {{ $order->shipping_province }}, {{ $order->shipping_country }}</dd>
                    <dt class="text-slate-500">Ghi chú</dt><dd>{{ $order->delivery_note ?? '—' }}</dd>
                    <dt class="text-slate-500">Kho</dt><dd>{{ $order->warehouse?->name ?? '—' }}</dd></dl>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Lịch sử đơn</h2>
                @forelse ($order->histories as $event)
                    <div class="mt-3 border-t border-slate-100 pt-3 text-sm"><p class="font-semibold">{{ $event->event_type }} · {{ $event->from_status ?? '—' }} → {{ $event->to_status ?? '—' }}</p>
                        <p class="text-slate-500">{{ $event->created_at?->format('d/m/Y H:i') }} · {{ $event->actor?->name ?? 'Hệ thống' }}</p></div>
                @empty
                    <p class="mt-3 text-sm text-slate-500">Chưa có lịch sử.</p>
                @endforelse
            </section>
        </div>
        <aside class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:sticky lg:top-6"><h2 class="text-xl font-semibold text-junie-navy">Thanh toán</h2>
            <p class="text-sm">{{ $order->payment_method === 'cod' ? 'Thanh toán khi nhận hàng (COD)' : 'Chuyển khoản ngân hàng' }}</p>
            <dl class="space-y-2 text-sm"><div class="flex justify-between gap-2"><dt>Tạm tính</dt><dd><x-formatted-number :value="$order->subtotal" currency /></dd></div>
                <div class="flex justify-between gap-2"><dt>Giảm giá</dt><dd>−<x-formatted-number :value="$order->discount_total" currency /></dd></div>
                @if ($order->sales_voucher_code_snapshot)
                    <div class="flex justify-between gap-2 text-slate-500"><dt>Trong giảm giá: voucher {{ $order->sales_voucher_code_snapshot }}</dt><dd><x-formatted-number :value="$order->sales_voucher_discount_snapshot" currency /></dd></div>
                @endif
                <div class="flex justify-between gap-2 border-t border-slate-200 pt-3 text-lg font-semibold"><dt>Tổng đơn</dt><dd><x-formatted-number :value="$order->grand_total" currency /></dd></div>
                <div class="flex justify-between gap-2"><dt>Đã thanh toán</dt><dd><x-formatted-number :value="$paymentSummary['paid_amount']" currency /></dd></div>
                <div class="flex justify-between gap-2"><dt>Còn lại</dt><dd><x-formatted-number :value="$paymentSummary['outstanding_amount']" currency /></dd></div></dl>
            @if ($order->payment_status !== 'paid')
                <p class="text-xs text-slate-600">Đặt hàng không tự xác nhận đã thanh toán. Trạng thái sẽ cập nhật theo xử lý thực tế.</p>
            @endif
        </aside>
    </div>
@endsection
