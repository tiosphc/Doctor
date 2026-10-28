@extends('layouts.admin')

@section('title', 'Đơn bán hàng')
@section('page-title', 'Đơn bán hàng')

@section('content')
<div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-3xl font-semibold text-junie-navy">Đơn bán hàng</h1>
            <p class="mt-2 text-sm text-slate-600">Theo dõi đơn Retail và Dealer, trạng thái thanh toán và kho xuất.</p>
        </div>
        <a href="{{ route('admin.web.sales.orders.create') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Tạo đơn Retail</a>
    </header>

    <form method="GET" action="{{ route('admin.web.sales.orders.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4" aria-label="Lọc đơn bán hàng">
        <label class="sm:col-span-2 lg:col-span-4"><span class="mb-1 block text-sm font-medium">Tìm đơn hàng</span><input name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Mã đơn, người nhận, điện thoại, tham chiếu" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
        <label><span class="mb-1 block text-sm font-medium">Kênh bán</span><select name="sales_channel" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="">Tất cả</option><option value="retail" @selected(($filters['sales_channel'] ?? '') === 'retail')>Retail</option><option value="dealer" @selected(($filters['sales_channel'] ?? '') === 'dealer')>Dealer</option></select></label>
        <label><span class="mb-1 block text-sm font-medium">Trạng thái đơn</span><select name="order_status" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="">Tất cả</option>@foreach (['draft', 'pending', 'confirmed', 'preparing', 'shipping', 'processing', 'delivered', 'completed', 'cancelled'] as $status)<option value="{{ $status }}" @selected(($filters['order_status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
        <label><span class="mb-1 block text-sm font-medium">Thanh toán</span><select name="payment_status" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="">Tất cả</option>@foreach (['unpaid', 'pending', 'partially_paid', 'paid', 'partially_refunded', 'refunded'] as $status)<option value="{{ $status }}" @selected(($filters['payment_status'] ?? '') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>@endforeach</select></label>
        <label><span class="mb-1 block text-sm font-medium">Kho</span><select name="warehouse_id" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="">Tất cả</option>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(($filters['warehouse_id'] ?? '') == $warehouse->id)>{{ $warehouse->name }} ({{ $warehouse->code }})</option>@endforeach</select></label>
        <label><span class="mb-1 block text-sm font-medium">Từ ngày</span><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
        <label><span class="mb-1 block text-sm font-medium">Đến ngày</span><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
        <div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4"><button class="min-h-11 rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Áp dụng</button><a href="{{ route('admin.web.sales.orders.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold">Xóa bộ lọc</a></div>
    </form>

    <p class="text-sm text-slate-600">{{ $orders->total() }} đơn · Trang {{ $orders->currentPage() }}/{{ $orders->lastPage() }}</p>
    @if ($orders->isEmpty())
        <x-empty-state title="Không tìm thấy đơn hàng phù hợp." />
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-[900px] w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-600"><tr><th class="p-4">Đơn hàng</th><th class="p-4">Người mua / nhận</th><th class="p-4">Kênh</th><th class="p-4">Trạng thái</th><th class="p-4">Thanh toán</th><th class="p-4">Kho</th><th class="p-4 text-right">Tổng tiền</th><th class="p-4">Ngày tạo</th><th class="p-4">Thao tác</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($orders as $order)
                        <tr>
                            <td class="p-4"><a href="{{ route('admin.web.sales.orders.show', $order) }}" class="inline-flex min-h-11 items-center font-semibold text-junie-navy underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-junie-navy">{{ $order->order_code }}</a><p class="text-xs text-slate-500">{{ $order->items_count }} SKU</p></td>
                            <td class="p-4"><p>{{ $order->buyer?->name ?? $order->dealer_name_snapshot ?? '—' }}</p><p class="text-xs text-slate-500">{{ $order->recipient_name }}</p></td>
                            <td class="p-4">{{ ucfirst($order->sales_channel) }}</td><td class="p-4"><x-badge>{{ $order->order_status }}</x-badge></td><td class="p-4"><x-badge :tone="$order->payment_status === 'paid' ? 'success' : 'gold'">{{ $order->payment_status }}</x-badge></td>
                            <td class="p-4">{{ $order->warehouse?->name ?? '—' }}</td><td class="p-4 text-right font-semibold"><x-formatted-number :value="$order->grand_total" currency /></td><td class="p-4 whitespace-nowrap">{{ $order->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="p-4"><a href="{{ route('admin.web.sales.orders.show', $order) }}" class="inline-flex min-h-11 items-center whitespace-nowrap rounded-lg border border-junie-navy px-3 font-semibold text-junie-navy hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-junie-navy" aria-label="Xem chi tiết đơn {{ $order->order_code }}">Xem chi tiết</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <x-pagination :paginator="$orders" />
</div>
@endsection
