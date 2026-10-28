@extends('layouts.customer')

@section('title', 'Đơn hàng của tôi')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3"><div>
        <p class="text-xs font-semibold uppercase tracking-widest text-junie-gold">Junie Retail</p>
        <h1 class="mt-2 text-3xl font-semibold text-junie-navy">Đơn hàng của tôi</h1></div>
        <a href="{{ route('retail.web.shop.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">Tiếp tục mua sắm →</a></div>
    <form method="GET" action="{{ route('retail.web.orders.index') }}" class="mb-6 flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4">
        <label class="text-sm font-medium">Trạng thái
            <select name="order_status" class="mt-1 min-h-11 w-48 rounded-xl border border-slate-300 px-3 text-sm">
                <option value="">Tất cả</option>
                @foreach (['pending', 'confirmed', 'preparing', 'shipping', 'processing', 'delivered', 'completed', 'cancelled'] as $status)
                    <option value="{{ $status }}" @selected(($filters['order_status'] ?? '') === $status)>{{ $status }}</option>
                @endforeach
            </select>
        </label>
        <x-button type="submit">Lọc</x-button>
        <a href="{{ route('retail.web.orders.index') }}" class="inline-flex min-h-11 items-center text-sm text-slate-600 hover:underline">Xóa lọc</a>
    </form>
    @if ($orders->isEmpty())
        <x-empty-state title="Bạn chưa có đơn hàng phù hợp." />
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($orders as $order)
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div>
                        <a href="{{ route('retail.web.orders.show', $order->id) }}" class="text-lg font-semibold text-junie-navy hover:underline">{{ $order->order_code }}</a>
                        <p class="mt-1 text-xs text-slate-500">{{ $order->created_at?->format('d/m/Y H:i') }} · {{ $order->items_count }} mặt hàng</p>
                    </div><strong><x-formatted-number :value="$order->grand_total" currency /></strong></div>
                    <div class="mt-4 flex flex-wrap gap-2"><x-badge>{{ $order->order_status }}</x-badge><x-badge :tone="$order->payment_status === 'paid' ? 'success' : 'gold'">{{ $order->payment_status }}</x-badge><x-badge>{{ $order->fulfillment_status }}</x-badge></div>
                    <p class="mt-3 text-sm text-slate-600">Người nhận: {{ $order->recipient_name }} · {{ $order->payment_method === 'cod' ? 'COD' : 'Chuyển khoản' }}</p>
                    <a href="{{ route('retail.web.orders.show', $order->id) }}" class="mt-4 inline-flex min-h-11 items-center text-sm font-semibold text-junie-navy hover:underline">Xem chi tiết →</a>
                </article>
            @endforeach
        </div>
        <div class="mt-6"><x-pagination :paginator="$orders" /></div>
    @endif
@endsection
