@extends('layouts.dealer')

@section('title', 'Sản phẩm đại lý')
@section('page-title', 'Sản phẩm đại lý')

@section('content')
<div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4"><div><h1 class="text-3xl font-semibold text-junie-navy">Danh mục sản phẩm đại lý</h1><p class="mt-2 text-sm text-slate-600">{{ $account->legal_name }} · Giá theo {{ $effectiveTier['name'] ?? 'Tier hiện tại' }}. Giá cuối cùng được kiểm tra lại khi xem trước đơn theo địa chỉ giao hàng.</p></div><a href="{{ route('dealer.web.quick.create') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Đặt hàng nhanh</a></header>
    <form method="GET" action="{{ route('dealer.web.products.index') }}" class="flex flex-wrap gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><label class="min-w-0 flex-1 text-sm font-semibold">Tìm sản phẩm, mã hoặc SKU<input name="search" value="{{ $search }}" maxlength="100" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"></label><div class="flex items-end gap-2"><button class="min-h-11 rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Tìm</button><a href="{{ route('dealer.web.products.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm">Xóa lọc</a></div></form>
    <p class="text-sm text-slate-600">{{ $meta['total'] }} sản phẩm · Trang {{ $meta['current_page'] }}/{{ $meta['last_page'] }}</p>
    @if (count($products) === 0)
        <x-empty-state title="Chưa có sản phẩm đại lý phù hợp với Tier hiện tại." />
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($products as $product)
                @php($first = $product['variants'][0] ?? null)
                <article class="min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <a href="{{ route('dealer.web.products.show', $product['slug'] ?? $product['id']) }}" class="block focus-visible:outline focus-visible:outline-2 focus-visible:outline-junie-navy">
                        <div class="aspect-[4/3] bg-slate-100">@if (!empty($product['images'][0]['url']))<img src="{{ $product['images'][0]['url'] }}" alt="{{ $product['images'][0]['alt_text'] ?: $product['name'] }}" loading="lazy" class="h-full w-full object-cover">@endif</div>
                        <div class="space-y-2 p-5"><p class="text-xs text-slate-500">{{ $product['category']['name'] ?? '—' }} · {{ $product['brand']['name'] ?? '—' }}</p><h2 class="break-words text-lg font-semibold text-junie-navy">{{ $product['name'] }}</h2><p class="font-mono text-xs text-slate-500">{{ $product['product_code'] }}</p><p class="text-sm text-slate-600">{{ count($product['variants']) }} SKU có giá Dealer</p>@if ($first)<p class="text-sm font-semibold">Giá SKU {{ $first['sku'] }}: <x-formatted-number :value="$first['dealer_price']['unit_price']" currency /></p><p class="text-xs text-slate-600">MOQ {{ $first['dealer_price']['minimum_quantity'] }} {{ $first['unit_symbol'] ?: $first['unit'] }} · {{ $effectiveTier['name'] ?? 'Tier hiện tại' }}</p>@endif @if (!empty($product['gift_promotions']) || !empty($product['dealer_discount_promotion']))<p class="text-xs font-semibold text-amber-700">Có ưu đãi theo điều kiện</p>@endif</div>
                    </a>
                </article>
            @endforeach
        </div>
    @endif
    <nav class="flex items-center justify-between gap-3 text-sm" aria-label="Phân trang sản phẩm"><div>@if ($meta['current_page'] > 1)<a class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 font-semibold" href="{{ route('dealer.web.products.index', ['search' => $search, 'page' => $meta['current_page'] - 1]) }}">← Trước</a>@endif</div><div>@if ($meta['current_page'] < $meta['last_page'])<a class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 font-semibold" href="{{ route('dealer.web.products.index', ['search' => $search, 'page' => $meta['current_page'] + 1]) }}">Tiếp →</a>@endif</div></nav>
</div>
@endsection
