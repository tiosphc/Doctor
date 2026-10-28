@extends('layouts.admin')

@section('title', 'Khuyến mãi · '.$promotion->code)
@section('page-title', 'Khuyến mãi bán hàng')

@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <div class="flex flex-wrap gap-4 text-sm font-semibold text-junie-navy"><a href="{{ route('admin.web.sales-promotions.index') }}" class="underline">← Danh sách khuyến mãi</a><a href="{{ route('admin.web.sales-promotions.edit', $promotion) }}" class="underline">Sửa chương trình</a></div>
    <header class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">{{ $promotion->code }}</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">{{ $promotion->name }}</h1><p class="mt-2 text-sm text-slate-600">{{ $promotion->sales_scope }} · Trạng thái lưu: {{ $promotion->status }}</p></div><x-badge :tone="$promotion->status === 'active' && ! $promotion->starts_at?->isFuture() && ! $promotion->ends_at?->isPast() ? 'success' : 'gold'">{{ $promotion->status === 'inactive' ? 'Đã tắt' : ($promotion->starts_at?->isFuture() ? 'Sắp bắt đầu' : ($promotion->ends_at?->isPast() ? 'Đã hết hạn' : 'Đang áp dụng')) }}</x-badge></header>
    @error('operation')<x-alert tone="error">{{ $message }}</x-alert>@enderror
    @error('gift_rule')<x-alert tone="error">{{ $message }}</x-alert>@enderror
    <div class="flex flex-wrap gap-3">
        @if($promotion->status === 'active')
            <x-confirm-modal :action="route('admin.web.sales-promotions.deactivate', $promotion)" title="Tắt khuyến mãi" :message="'Xác nhận tắt '.$promotion->code.'?'" confirm-label="Tắt chương trình" tone="danger"><x-slot:trigger>Tắt chương trình</x-slot:trigger></x-confirm-modal>
        @else
            <x-confirm-modal :action="route('admin.web.sales-promotions.activate', $promotion)" title="Bật khuyến mãi" :message="'Xác nhận bật '.$promotion->code.'? Service sẽ kiểm tra xung đột và Gift rule.'" confirm-label="Bật chương trình" tone="primary"><x-slot:trigger>Bật chương trình</x-slot:trigger></x-confirm-modal>
        @endif
    </div>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-junie-navy">Điều kiện đã lưu</h2>
        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-slate-500">Loại ưu đãi</dt><dd class="mt-1 font-semibold">{{ ['percentage' => 'Giảm phần trăm', 'fixed_amount' => 'Giảm số tiền mỗi đơn vị', 'buy_a_get_b' => 'Mua A tặng A/B'][$promotion->discount_type] ?? $promotion->discount_type }}</dd></div>
            <div><dt class="text-slate-500">Giá trị</dt><dd class="mt-1 font-semibold">@if($promotion->discount_type === 'buy_a_get_b')Quà tặng@elseif($promotion->discount_type === 'percentage')<x-formatted-number :value="$promotion->discount_value" />%@else<x-formatted-number :value="$promotion->discount_value" currency />@endif</dd></div>
            <div><dt class="text-slate-500">Giảm tối đa / đơn tối thiểu</dt><dd class="mt-1">{{ $promotion->max_discount_amount === null ? 'Không giới hạn' : '' }}@if($promotion->max_discount_amount !== null)<x-formatted-number :value="$promotion->max_discount_amount" currency />@endif · <x-formatted-number :value="$promotion->minimum_order_amount" currency /></dd></div>
            <div><dt class="text-slate-500">Thời gian</dt><dd class="mt-1">{{ $promotion->starts_at?->format('d/m/Y H:i') ?? 'Không giới hạn' }} → {{ $promotion->ends_at?->format('d/m/Y H:i') ?? 'Không giới hạn' }}</dd></div>
            <div><dt class="text-slate-500">Lượt sử dụng</dt><dd class="mt-1">{{ $promotion->redeemed_count }} / {{ $promotion->total_usage_limit ?? '∞' }} · mỗi người mua/đại lý {{ $promotion->per_buyer_usage_limit ?? '∞' }}</dd></div>
            <div><dt class="text-slate-500">Tier đại lý</dt><dd class="mt-1">{{ $promotion->dealerTiers->isEmpty() ? 'Không giới hạn' : $promotion->dealerTiers->pluck('name')->join(', ') }}</dd></div>
        </dl>
        @if($promotion->description)<p class="mt-5 whitespace-pre-wrap break-words border-t border-slate-200 pt-4 text-sm text-slate-700">{{ $promotion->description }}</p>@endif
    </section>
    @if($promotion->discount_type === 'buy_a_get_b')
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Quy tắc quà tặng</h2>@if($promotion->giftRule)<dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2"><div><dt class="text-slate-500">Product mua</dt><dd class="font-semibold">{{ $promotion->giftRule->buyProduct?->product_code }} · {{ $promotion->giftRule->buyProduct?->name }}</dd></div><div><dt class="text-slate-500">Product tặng</dt><dd class="font-semibold">{{ $promotion->giftRule->giftProduct?->product_code }} · {{ $promotion->giftRule->giftProduct?->name }}</dd></div><div><dt class="text-slate-500">SKU mua</dt><dd>{{ $promotion->giftRule->buyVariant?->sku ?? 'Mọi SKU hợp lệ' }}</dd></div><div><dt class="text-slate-500">SKU tặng</dt><dd>{{ $promotion->giftRule->giftVariant?->sku ?? '—' }}</dd></div><div><dt class="text-slate-500">Số lượng</dt><dd>Mua {{ $promotion->giftRule->minimum_buy_quantity }} tặng {{ $promotion->giftRule->gift_quantity }}</dd></div><div><dt class="text-slate-500">Lặp theo bội số</dt><dd>{{ $promotion->giftRule->repeat_per_multiple ? 'Có' : 'Không' }}</dd></div></dl>@else<p class="mt-3 text-sm text-red-700">Không có Gift rule hợp lệ.</p>@endif</section>
    @else
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Product / danh mục áp dụng</h2><div class="mt-3 flex flex-wrap gap-2">@forelse($targetProducts as $product)<span class="rounded-lg bg-slate-100 px-3 py-1 text-sm">{{ $product->product_code }} · {{ $product->name }}</span>@empty @endforelse @foreach($targetCategories as $category)<span class="rounded-lg bg-amber-50 px-3 py-1 text-sm">{{ $category->code }} · {{ $category->name }}</span>@endforeach @if($targetProducts->isEmpty() && $targetCategories->isEmpty())<p class="text-sm text-slate-600">Không giới hạn target theo contract hiện có.</p>@endif</div></section>
    @endif
    <section class="space-y-3"><h2 class="text-lg font-semibold text-junie-navy">Lịch sử áp dụng ({{ $redemptions->total() }})</h2>@if($redemptions->isEmpty())<x-empty-state title="Chưa có lượt áp dụng." />@else<div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table class="w-full min-w-[700px] text-left text-sm"><thead class="bg-slate-50"><tr><th class="px-4 py-3">Đơn hàng</th><th class="px-4 py-3">Kênh</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Giảm giá</th><th class="px-4 py-3">Thời điểm</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach($redemptions as $item)<tr><td class="px-4 py-3"><a href="{{ route('admin.web.sales.orders.show', $item->sales_order_id) }}" class="font-semibold text-junie-navy underline">{{ $item->salesOrder?->order_code ?? '#'.$item->sales_order_id }}</a></td><td class="px-4 py-3">{{ $item->sales_channel }}</td><td class="px-4 py-3">{{ $item->status }}</td><td class="px-4 py-3"><x-formatted-number :value="$item->discount_amount" currency /></td><td class="px-4 py-3">{{ $item->redeemed_at?->format('d/m/Y H:i') ?? '—' }}</td></tr>@endforeach</tbody></table></div><x-pagination :paginator="$redemptions" />@endif</section>
</div>
@endsection
