@extends('layouts.customer')

@section('title', 'Sản phẩm Retail')

@section('content')
    <div class="mb-6">
        <p class="text-xs font-semibold uppercase tracking-widest text-junie-gold">Junie Retail</p>
        <h1 class="mt-2 text-3xl font-semibold text-junie-navy">Chọn sản phẩm</h1>
    </div>
    <form method="GET" action="{{ route('retail.web.shop.index') }}" class="mb-5 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))_auto] lg:items-end">
        <div class="min-w-0">
            <label for="shop-search" class="mb-1 block text-sm font-semibold text-slate-700">Tìm sản phẩm hoặc SKU</label>
            <input id="shop-search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Tên, mã sản phẩm hoặc SKU" maxlength="100" class="min-h-11 w-full min-w-0 rounded-xl border border-slate-300 px-3 text-sm">
        </div>
        <div class="min-w-0">
            <label for="shop-category" class="mb-1 block text-sm font-semibold text-slate-700">Danh mục</label>
            <select id="shop-category" name="category" class="min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 text-sm">
                <option value="">Tất cả danh mục</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) ($filters['category'] ?? '') === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-0">
            <label for="shop-brand" class="mb-1 block text-sm font-semibold text-slate-700">Thương hiệu</label>
            <select id="shop-brand" name="brand" class="min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 text-sm">
                <option value="">Tất cả thương hiệu</option>
                @foreach ($brands as $brand)
                    <option value="{{ $brand->id }}" @selected((string) ($filters['brand'] ?? '') === (string) $brand->id)>{{ $brand->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-0">
            <label for="shop-sort" class="mb-1 block text-sm font-semibold text-slate-700">Sắp xếp</label>
            <select id="shop-sort" name="sort" class="min-h-11 w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 text-sm">
                <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>Mới nhất</option>
                <option value="name" @selected(($filters['sort'] ?? 'newest') === 'name')>Tên sản phẩm</option>
            </select>
        </div>
        <div class="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-1">
            <x-button type="submit">Áp dụng</x-button>
            <a href="{{ route('retail.web.shop.index') }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-junie-navy hover:underline">Xóa lọc</a>
        </div>
    </form>
    <p class="mb-4 text-sm text-slate-600" role="status">{{ $variants->total() }} quy cách phù hợp</p>
    @if ($variants->isEmpty())
        <x-empty-state title="Không có sản phẩm phù hợp." />
    @else
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($variants as $variant)
                <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <a href="{{ route('retail.web.shop.show', $variant['slug']) }}" class="block aspect-square bg-slate-100">
                        @if ($variant['image_url'])
                            <img src="{{ $variant['image_url'] }}" alt="{{ $variant['name'] }}" loading="lazy" class="h-full w-full object-cover">
                        @endif
                    </a>
                    <div class="space-y-2 p-4">
                        <a href="{{ route('retail.web.shop.show', $variant['slug']) }}" class="font-semibold text-junie-navy hover:underline">{{ $variant['name'] }}</a>
                        <p class="text-sm text-slate-600">{{ $variant['variant_name'] }} · {{ $variant['sku'] }}</p>
                        <p class="font-semibold"><x-formatted-number :value="$variant['price']" currency /> / {{ $variant['unit'] }}</p>
                        <a href="{{ route('retail.web.shop.show', $variant['slug']) }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-junie-navy hover:underline">Chọn quy cách →</a>
                    </div>
                </article>
            @endforeach
        </div>
        @if ($variants->hasPages())
            <nav aria-label="Phân trang" class="mt-6">{{ $variants->links() }}</nav>
        @endif
    @endif
@endsection
