@extends('layouts.admin')

@section('title', 'Sản phẩm')
@section('page-title', 'Sản phẩm')

@section('content')
    <div class="space-y-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Product Master</p>
                <h1 class="mt-2 text-3xl font-semibold text-junie-navy">Sản phẩm</h1>
                <p class="mt-2 text-sm text-slate-600">Quản lý mã sản phẩm, SKU và khả năng bán Retail.</p>
            </div>
            <div class="flex flex-wrap gap-2"><a href="{{ route('admin.web.products.drafts.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-junie-navy px-4 py-2 text-sm font-semibold text-junie-navy">Sản phẩm nháp</a><a href="{{ route('admin.web.products.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-junie-navy px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Thêm sản phẩm</a></div>
        </header>

        <form method="GET" action="{{ route('admin.web.products.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4" aria-label="Tìm và lọc sản phẩm">
            <label class="block sm:col-span-2 lg:col-span-4">
                <span class="mb-1 block text-sm font-medium text-slate-700">Tìm sản phẩm</span>
                <input name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Tên, mã hoặc SKU" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-junie-navy focus:ring-2 focus:ring-junie-navy/15">
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-slate-700">Danh mục</span>
                <select name="category" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                    <option value="">Tất cả danh mục</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(($filters['category'] ?? null) == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-slate-700">Thương hiệu</span>
                <select name="brand" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                    <option value="">Tất cả thương hiệu</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected(($filters['brand'] ?? null) == $brand->id)>{{ $brand->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-slate-700">Loại sản phẩm</span>
                <select name="gift_filter" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                    <option value="all">Tất cả sản phẩm</option>
                    <option value="gift_capable" @selected(($filters['gift_filter'] ?? 'all') === 'gift_capable')>Có thể làm quà</option>
                    <option value="gift_only" @selected(($filters['gift_filter'] ?? 'all') === 'gift_only')>Chỉ quà tặng</option>
                    <option value="normal" @selected(($filters['gift_filter'] ?? 'all') === 'normal')>Bán bình thường</option>
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-slate-700">Trạng thái</span>
                <select name="status" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                    <option value="">Tất cả trạng thái</option>
                    <option value="draft" @selected(($filters['status'] ?? null) === 'draft')>Draft</option>
                    <option value="active" @selected(($filters['status'] ?? null) === 'active')>Active</option>
                    <option value="inactive" @selected(($filters['status'] ?? null) === 'inactive')>Inactive</option>
                </select>
            </label>
            <div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-button type="submit">Áp dụng</x-button>
                <a href="{{ route('admin.web.products.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Xóa bộ lọc</a>
            </div>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-slate-500">
            <p>{{ $products->total() }} sản phẩm · Trang {{ $products->currentPage() }}/{{ $products->lastPage() }}</p>
            <p>20 sản phẩm mỗi trang</p>
        </div>

        @if ($visibleProducts->isEmpty())
            <x-empty-state :title="filled($filters['search'] ?? null) || filled($filters['category'] ?? null) || filled($filters['brand'] ?? null) || filled($filters['status'] ?? null) || ($filters['gift_filter'] ?? 'all') !== 'all' ? 'Không tìm thấy sản phẩm phù hợp.' : 'Chưa có sản phẩm nào hiển thị trên trang này.'" />
        @else
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($visibleProducts as $product)
                    <article class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <a href="{{ route('admin.web.products.show', $product) }}" class="relative block overflow-hidden" aria-label="Xem chi tiết {{ $product->name }}">
                            <x-product-image :image="$primaryImages[$product->id] ?? null" :alt="$product->name" class="aspect-[4/3] w-full" />
                            <span class="absolute right-3 top-3"><x-product-status :status="$product->status" /></span>
                        </a>
                        <div class="flex flex-1 flex-col gap-2 p-4">
                            <span class="w-fit rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-junie-navy">{{ $product->gift_only ? 'Chỉ quà tặng' : ($product->can_be_gift ? 'Bán + Quà' : 'Bán hàng') }}</span>
                            <p class="text-xs text-slate-500">{{ $product->category?->name ?? 'Chưa có danh mục' }}@if ($product->brand) · {{ $product->brand->name }}@endif</p>
                            <a href="{{ route('admin.web.products.show', $product) }}" class="line-clamp-2 break-words text-base font-semibold text-junie-navy hover:underline">{{ $product->name }}</a>
                            <p class="break-all font-mono text-xs text-slate-500">{{ $product->product_code }}</p>
                            <p class="text-xs text-slate-500">{{ $product->variants_count }} SKU</p>
                            @if ($product->gift_only)
                                <p class="mt-auto pt-2 text-xs font-medium text-slate-600">Không bán trực tiếp</p>
                            @else
                                <p class="mt-auto pt-2 text-xs text-slate-500">Giá Retail theo từng SKU · xem chi tiết</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-2 border-t border-slate-200 p-3 text-xs font-semibold">
                            <a href="{{ route('admin.web.products.show', $product) }}" class="inline-flex min-h-10 flex-1 items-center justify-center rounded-lg bg-junie-navy px-3 text-white hover:bg-slate-800">Xem chi tiết</a>
                            <a href="{{ $product->status === 'draft' ? ($product->wizard_owner_user_id === auth()->id() ? route('admin.web.products.drafts.edit', $product) : route('admin.web.products.show', $product)) : route('admin.web.products.edit', $product) }}" class="inline-flex min-h-10 flex-1 items-center justify-center rounded-lg border border-slate-300 px-3 text-junie-navy hover:bg-slate-50">{{ $product->status === 'draft' ? ($product->wizard_owner_user_id === auth()->id() ? 'Mở bản nháp' : 'Xem bản nháp') : 'Chỉnh sửa' }}</a>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <x-pagination :paginator="$products" />
    </div>
@endsection
