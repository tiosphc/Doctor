@extends('layouts.admin')

@section('title', 'Voucher bán hàng')
@section('page-title', 'Voucher bán hàng')

@section('content')
<div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Ưu đãi Retail</p>
            <h1 class="mt-2 text-3xl font-semibold text-junie-navy">Voucher bán hàng</h1>
            <p class="mt-2 text-sm text-slate-600">Voucher chỉ áp dụng cho Retail. Lượt dùng được ghi khi checkout thành công.</p>
        </div>
        <a href="{{ route('admin.web.sales-vouchers.create') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Tạo voucher</a>
    </header>

    <form method="GET" action="{{ route('admin.web.sales-vouchers.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[minmax(0,1fr)_12rem_auto]">
        <label class="text-sm font-semibold">Tìm mã hoặc tên
            <input name="search" maxlength="80" value="{{ $filters['search'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal" placeholder="Nhập mã hoặc tên">
        </label>
        <label class="text-sm font-semibold">Hiệu lực
            <select name="status" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal">
                <option value="">Tất cả</option>
                @foreach(['active' => 'Đang áp dụng', 'upcoming' => 'Sắp bắt đầu', 'expired' => 'Đã hết hạn', 'inactive' => 'Đã tắt'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex flex-wrap items-end gap-2"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.sales-vouchers.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold">Xóa lọc</a></div>
    </form>

    <section class="space-y-3">
        <p class="text-sm text-slate-500">{{ $vouchers->total() }} voucher · Trang {{ $vouchers->currentPage() }}/{{ $vouchers->lastPage() }}</p>
        @if($vouchers->isEmpty())
            <x-empty-state title="Không có voucher phù hợp." />
        @else
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full min-w-[850px] text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Voucher</th><th class="px-4 py-3">Giá trị</th><th class="px-4 py-3">Thời gian</th><th class="px-4 py-3">Lượt dùng</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3"></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach($vouchers as $voucher)
                        @php
                            $effective = $voucher->status === 'inactive' ? 'Đã tắt' : ($voucher->starts_at?->isFuture() ? 'Sắp bắt đầu' : ($voucher->ends_at?->isPast() ? 'Đã hết hạn' : 'Đang áp dụng'));
                        @endphp
                        <tr>
                            <td class="px-4 py-3"><a href="{{ route('admin.web.sales-vouchers.show', $voucher) }}" class="font-semibold text-junie-navy underline">{{ $voucher->code }}</a><span class="mt-1 block max-w-64 truncate text-slate-600">{{ $voucher->name }}</span></td>
                            <td class="px-4 py-3 font-semibold">@if($voucher->discount_type === 'percentage')<x-formatted-number :value="$voucher->discount_value" />%@else<x-formatted-number :value="$voucher->discount_value" currency />@endif</td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $voucher->starts_at?->format('d/m/Y H:i') ?? 'Không giới hạn' }}<span class="block">→ {{ $voucher->ends_at?->format('d/m/Y H:i') ?? 'Không giới hạn' }}</span></td>
                            <td class="px-4 py-3">{{ $voucher->redeemed_count }} / {{ $voucher->total_usage_limit ?? '∞' }}</td>
                            <td class="px-4 py-3"><x-badge :tone="$effective === 'Đang áp dụng' ? 'success' : ($effective === 'Đã tắt' ? 'danger' : 'gold')">{{ $effective }}</x-badge><span class="mt-1 block text-xs text-slate-500">Lưu: {{ $voucher->status }}</span></td>
                            <td class="px-4 py-3 text-right"><a href="{{ route('admin.web.sales-vouchers.edit', $voucher) }}" class="font-semibold text-junie-navy underline">Sửa</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$vouchers" />
        @endif
    </section>
</div>
@endsection
