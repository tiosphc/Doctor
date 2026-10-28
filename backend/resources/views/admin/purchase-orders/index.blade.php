@extends('layouts.admin')
@section('title', 'Phiếu mua')
@section('page-title', 'Phiếu mua')
@section('content')
@php($statuses = ['draft' => 'Nháp', 'ordered' => 'Đã phát hành', 'partially_received' => 'Nhận một phần', 'received' => 'Đã nhận đủ', 'cancelled' => 'Đã hủy'])
<div class="space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Procurement</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">Phiếu mua</h1></div><a href="{{ route('admin.web.purchase-orders.create') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Tạo phiếu mua</a></header>
    <form method="GET" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 xl:grid-cols-5">
        <label class="text-sm font-semibold">Mã PO<input name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"></label>
        <label class="text-sm font-semibold">Nhà cung cấp<select name="supplier_id" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Tất cả</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(($filters['supplier_id'] ?? '') == $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">Kho<select name="warehouse_id" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Tất cả</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(($filters['warehouse_id'] ?? '') == $warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">Trạng thái<select name="status" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Tất cả</option>@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
        <div class="flex items-end gap-2"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.purchase-orders.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-3 text-sm">Xóa lọc</a></div>
    </form>
    <p class="text-sm text-slate-500">{{ $orders->total() }} phiếu mua</p>
    @if($orders->isEmpty())<x-empty-state title="Chưa có phiếu mua phù hợp." />@else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table class="w-full min-w-[900px] text-left text-sm"><thead class="bg-slate-50"><tr><th class="px-4 py-3">Phiếu mua</th><th class="px-4 py-3">Nhà cung cấp</th><th class="px-4 py-3">Kho</th><th class="px-4 py-3">Số dòng</th><th class="px-4 py-3">Tổng giá mua</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Thanh toán</th></tr></thead><tbody class="divide-y">@foreach($orders as $order)<tr><td class="px-4 py-3"><a href="{{ route('admin.web.purchase-orders.show', $order) }}" class="font-semibold text-junie-navy underline">{{ $order->code }}</a><p class="text-xs text-slate-500">{{ $order->created_at?->format('d/m/Y H:i') }}</p></td><td class="px-4 py-3">{{ $order->supplier?->name }}</td><td class="px-4 py-3">{{ $order->warehouse?->name }}</td><td class="px-4 py-3">{{ $order->items_count }}</td><td class="px-4 py-3 tabular-nums"><x-formatted-number :value="$order->total_amount" :currency="true" /></td><td class="px-4 py-3">{{ $statuses[$order->status] ?? $order->status }}</td><td class="px-4 py-3">{{ $order->payment_status }}</td></tr>@endforeach</tbody></table></div><x-pagination :paginator="$orders" />
    @endif
</div>
@endsection
