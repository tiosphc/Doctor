@extends('layouts.admin')

@section('title', 'Khuyến mãi bán hàng')
@section('page-title', 'Khuyến mãi bán hàng')

@section('content')
<div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Ưu đãi đơn hàng</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">Khuyến mãi bán hàng</h1><p class="mt-2 text-sm text-slate-600">Giảm giá Product và Mua A tặng A/B. Trạng thái lưu và hiệu lực theo thời gian được hiển thị riêng.</p></div><a href="{{ route('admin.web.sales-promotions.create') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Tạo khuyến mãi</a></header>
    <form method="GET" action="{{ route('admin.web.sales-promotions.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-5">
        <label class="text-sm font-semibold">Tìm mã hoặc tên<input name="search" maxlength="80" value="{{ $filters['search'] ?? '' }}" placeholder="Mã hoặc tên" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"></label>
        <label class="text-sm font-semibold">Hiệu lực<select name="status" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"><option value="">Tất cả</option>@foreach(['active' => 'Đang áp dụng', 'upcoming' => 'Sắp bắt đầu', 'expired' => 'Đã hết hạn', 'inactive' => 'Đã tắt'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">Kênh<select name="sales_scope" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"><option value="">Tất cả</option>@foreach(['retail' => 'Retail', 'dealer' => 'Dealer', 'both' => 'Retail & Dealer'] as $value => $label)<option value="{{ $value }}" @selected(($filters['sales_scope'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">Loại<select name="discount_type" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"><option value="">Tất cả</option>@foreach(['percentage' => 'Phần trăm', 'fixed_amount' => 'Số tiền cố định', 'buy_a_get_b' => 'Mua A tặng A/B'] as $value => $label)<option value="{{ $value }}" @selected(($filters['discount_type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
        <div class="flex flex-wrap items-end gap-2"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.sales-promotions.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold">Xóa lọc</a></div>
    </form>
    <section class="space-y-3"><p class="text-sm text-slate-500">{{ $promotions->total() }} chương trình · Trang {{ $promotions->currentPage() }}/{{ $promotions->lastPage() }}</p>
        @if($promotions->isEmpty())<x-empty-state title="Không có khuyến mãi phù hợp." />@else
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="w-full min-w-[940px] text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Chương trình</th><th class="px-4 py-3">Loại / kênh</th><th class="px-4 py-3">Giá trị / target</th><th class="px-4 py-3">Hiệu lực</th><th class="px-4 py-3">Lượt dùng</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3"></th></tr></thead><tbody class="divide-y divide-slate-100">
            @foreach($promotions as $promotion)
                @php
                    $effective = $promotion->status === 'inactive' ? 'Đã tắt' : ($promotion->starts_at?->isFuture() ? 'Sắp bắt đầu' : ($promotion->ends_at?->isPast() ? 'Đã hết hạn' : 'Đang áp dụng'));
                @endphp
                <tr><td class="px-4 py-3"><a href="{{ route('admin.web.sales-promotions.show', $promotion) }}" class="font-semibold text-junie-navy underline">{{ $promotion->code }}</a><span class="mt-1 block max-w-56 truncate text-slate-600">{{ $promotion->name }}</span></td>
                    <td class="px-4 py-3">{{ ['percentage' => 'Phần trăm', 'fixed_amount' => 'Số tiền cố định', 'buy_a_get_b' => 'Mua A tặng A/B'][$promotion->discount_type] ?? $promotion->discount_type }}<span class="block text-xs text-slate-500">{{ $promotion->sales_scope }}</span></td>
                    <td class="px-4 py-3">@if($promotion->discount_type === 'buy_a_get_b')Quà: {{ $promotion->giftRule?->gift_quantity ?? '—' }}@elseif($promotion->discount_type === 'percentage')<x-formatted-number :value="$promotion->discount_value" />%@else<x-formatted-number :value="$promotion->discount_value" currency />@endif<span class="block text-xs text-slate-500">{{ $promotion->targets->count() }} target</span></td>
                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $promotion->starts_at?->format('d/m/Y H:i') ?? 'Không giới hạn' }}<span class="block">→ {{ $promotion->ends_at?->format('d/m/Y H:i') ?? 'Không giới hạn' }}</span></td>
                    <td class="px-4 py-3">{{ $promotion->redeemed_count }} / {{ $promotion->total_usage_limit ?? '∞' }}</td>
                    <td class="px-4 py-3"><x-badge :tone="$effective === 'Đang áp dụng' ? 'success' : ($effective === 'Đã tắt' ? 'danger' : 'gold')">{{ $effective }}</x-badge><span class="mt-1 block text-xs text-slate-500">Lưu: {{ $promotion->status }}</span></td>
                    <td class="px-4 py-3 text-right"><a href="{{ route('admin.web.sales-promotions.edit', $promotion) }}" class="font-semibold text-junie-navy underline">Sửa</a></td>
                </tr>
            @endforeach
            </tbody></table></div><x-pagination :paginator="$promotions" />
        @endif
    </section>
</div>
@endsection
