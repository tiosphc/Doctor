@extends('layouts.admin')

@section('title', 'Voucher · '.$voucher->code)
@section('page-title', 'Voucher bán hàng')

@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <div class="flex flex-wrap gap-4 text-sm font-semibold text-junie-navy"><a href="{{ route('admin.web.sales-vouchers.index') }}" class="underline">← Danh sách voucher</a><a href="{{ route('admin.web.sales-vouchers.edit', $voucher) }}" class="underline">Sửa voucher</a></div>
    <header class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">{{ $voucher->code }}</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">{{ $voucher->name }}</h1><p class="mt-2 text-sm text-slate-600">Voucher Retail · trạng thái lưu: {{ $voucher->status }}</p></div><x-badge :tone="$voucher->status === 'active' && ! $voucher->starts_at?->isFuture() && ! $voucher->ends_at?->isPast() ? 'success' : 'gold'">{{ $voucher->status === 'inactive' ? 'Đã tắt' : ($voucher->starts_at?->isFuture() ? 'Sắp bắt đầu' : ($voucher->ends_at?->isPast() ? 'Đã hết hạn' : 'Đang áp dụng')) }}</x-badge></header>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-slate-500">Kiểu giảm</dt><dd class="mt-1 font-semibold">{{ $voucher->discount_type === 'percentage' ? 'Phần trăm' : 'Số tiền cố định' }}</dd></div>
            <div><dt class="text-slate-500">Giá trị</dt><dd class="mt-1 font-semibold">@if($voucher->discount_type === 'percentage')<x-formatted-number :value="$voucher->discount_value" />%@else<x-formatted-number :value="$voucher->discount_value" currency />@endif</dd></div>
            <div><dt class="text-slate-500">Giảm tối đa</dt><dd class="mt-1 font-semibold">{{ $voucher->max_discount_amount === null ? 'Không giới hạn' : '' }}@if($voucher->max_discount_amount !== null)<x-formatted-number :value="$voucher->max_discount_amount" currency />@endif</dd></div>
            <div><dt class="text-slate-500">Đơn tối thiểu</dt><dd class="mt-1 font-semibold"><x-formatted-number :value="$voucher->minimum_order_amount" currency /></dd></div>
            <div><dt class="text-slate-500">Thời gian</dt><dd class="mt-1">{{ $voucher->starts_at?->format('d/m/Y H:i') ?? 'Không giới hạn' }} → {{ $voucher->ends_at?->format('d/m/Y H:i') ?? 'Không giới hạn' }}</dd></div>
            <div><dt class="text-slate-500">Lượt đã dùng</dt><dd class="mt-1 font-semibold">{{ $voucher->redeemed_count }} / {{ $voucher->total_usage_limit ?? '∞' }} · mỗi khách {{ $voucher->per_buyer_usage_limit ?? '∞' }}</dd></div>
        </dl>
        @if($voucher->description)<p class="mt-5 whitespace-pre-wrap break-words border-t border-slate-200 pt-4 text-sm text-slate-700">{{ $voucher->description }}</p>@endif
    </section>
    <section class="space-y-3"><h2 class="text-lg font-semibold text-junie-navy">Lịch sử sử dụng ({{ $redemptions->total() }})</h2>
        @if($redemptions->isEmpty())<x-empty-state title="Chưa có lượt sử dụng." />@else
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table class="w-full min-w-[650px] text-left text-sm"><thead class="bg-slate-50"><tr><th class="px-4 py-3">Đơn hàng</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Giá trị giảm</th><th class="px-4 py-3">Thời điểm</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach($redemptions as $item)<tr><td class="px-4 py-3"><a href="{{ route('admin.web.sales.orders.show', $item->sales_order_id) }}" class="font-semibold text-junie-navy underline">{{ $item->order_code ?? '#'.$item->sales_order_id }}</a></td><td class="px-4 py-3">{{ $item->status }}</td><td class="px-4 py-3"><x-formatted-number :value="$item->discount_amount" currency /></td><td class="px-4 py-3">{{ $item->redeemed_at?->format('d/m/Y H:i') ?? '—' }}</td></tr>@endforeach</tbody></table></div><x-pagination :paginator="$redemptions" />
        @endif
    </section>
</div>
@endsection
