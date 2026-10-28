@extends('layouts.admin')

@section('title', $product->name)
@section('page-title', 'Chi tiết sản phẩm')

@section('content')
    <div class="space-y-6">
        <a href="{{ route('admin.web.products.index') }}" class="inline-flex text-sm font-semibold text-junie-navy hover:underline">← Danh sách sản phẩm</a>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="break-all font-mono text-xs text-slate-500">{{ $product->product_code }}</p>
                <h1 class="mt-2 break-words text-2xl font-semibold text-junie-navy sm:text-3xl">{{ $product->name }}</h1>
                <div class="mt-3 flex flex-wrap gap-2"><x-product-status :status="$product->status" /><x-badge tone="navy">{{ $product->gift_only ? 'Chỉ quà tặng' : ($product->can_be_gift ? 'Bán + Quà' : 'Bán hàng') }}</x-badge></div>
            </div>
            @if($product->status !== 'draft' || $product->wizard_owner_user_id === auth()->id())<a href="{{ $product->status === 'draft' ? route('admin.web.products.drafts.edit', $product) : route('admin.web.products.edit', $product) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-junie-navy px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">{{ $product->status === 'draft' ? 'Tiếp tục hoàn tất sản phẩm' : 'Chỉnh sửa' }}</a>@endif
        </header>

        <section class="grid gap-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]" aria-labelledby="general-heading">
            <x-product-image :image="$primaryImage" :alt="$product->name" class="aspect-square w-full rounded-xl" />
            <div class="min-w-0">
                <h2 id="general-heading" class="text-xl font-semibold text-junie-navy">Thông tin chung</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Danh mục</dt><dd class="mt-1 font-medium text-slate-800">{{ $product->category?->name ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Thương hiệu</dt><dd class="mt-1 font-medium text-slate-800">{{ $product->brand?->name ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Slug</dt><dd class="mt-1 break-all font-medium text-slate-800">{{ $product->slug }}</dd></div>
                    <div><dt class="text-slate-500">Số SKU</dt><dd class="mt-1 font-medium text-slate-800">{{ $product->variants->count() }}</dd></div>
                </dl>
                @if ($product->description)
                    <div class="mt-5"><h3 class="text-sm font-semibold text-slate-800">Mô tả</h3><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ $product->description }}</p></div>
                @endif
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="variants-heading">
            <div class="flex flex-wrap items-end justify-between gap-2"><div><h2 id="variants-heading" class="text-xl font-semibold text-junie-navy">SKU / Biến thể</h2><p class="mt-1 text-sm text-slate-500">Giá và tồn kho được quản lý theo SKU.</p></div>@if($product->status !== 'draft')<a href="{{ route('admin.web.products.edit', $product) }}#variants-heading" class="text-sm font-semibold text-junie-navy hover:underline">Quản lý biến thể →</a>@elseif($product->wizard_owner_user_id === auth()->id())<a href="{{ route('admin.web.products.drafts.edit', $product) }}" class="text-sm font-semibold text-junie-navy hover:underline">Tiếp tục bản nháp →</a>@endif</div>
            @if ($product->variants->isEmpty())
                <p class="mt-5 text-sm text-slate-500">Sản phẩm chưa có biến thể.</p>
            @else
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full min-w-[690px] text-left text-sm">
                        <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3 font-semibold">SKU</th><th class="px-4 py-3 font-semibold">Biến thể</th><th class="px-4 py-3 font-semibold">Đơn vị</th><th class="px-4 py-3 font-semibold">Trạng thái</th><th class="px-4 py-3 font-semibold">Kênh bán</th><th class="px-4 py-3 font-semibold">Tồn kho</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($product->variants as $variant)
                                <tr>
                                    <td class="break-all px-4 py-3 font-mono text-xs font-semibold text-junie-navy">{{ $variant->sku }}</td>
                                    <td class="px-4 py-3">{{ $variant->variant_name }}</td>
                                    <td class="px-4 py-3">{{ $variant->unit?->symbol ?: ($variant->unit?->name ?? '—') }}</td>
                                    <td class="px-4 py-3"><x-product-status :status="$variant->status" /></td>
                                    <td class="px-4 py-3 text-slate-600">{{ collect([$variant->sellable_retail ? 'Retail' : null, $variant->sellable_dealer ? 'Đại lý' : null])->filter()->join(', ') ?: '—' }}</td>
                                    <td class="px-4 py-3 text-slate-600">@if($variant->track_inventory)<a href="{{ route('admin.web.inventory.show', $variant) }}" class="font-semibold text-junie-navy hover:underline">Xem theo kho →</a>@else Không theo dõi @endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="pricing-heading">
                <h2 id="pricing-heading" class="text-xl font-semibold text-junie-navy">Giá bán</h2>
                <p class="mt-2 text-sm text-slate-500">Giá được cấu hình theo SKU và kênh bán.</p>
                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Giá Retail tham chiếu</dt><dd class="mt-1 text-xl font-semibold text-junie-navy">@if ($pricing['retail_price'] !== null)<x-formatted-number :value="$pricing['retail_price']" :currency="true" />@else — @endif</dd></div>
                    <div><dt class="text-slate-500">Quy tắc giá đại lý</dt><dd class="mt-1 text-xl font-semibold text-junie-navy">{{ count($pricing['dealer_rules']) }}</dd></div>
                </dl>
                <div class="mt-5 flex flex-wrap gap-3 text-sm font-semibold"><a href="{{ route('admin.web.prices.retail.index', ['product_id' => $product->id]) }}" class="text-junie-navy hover:underline">Bảng giá Retail →</a><a href="{{ route('admin.web.prices.dealer.index', ['product_id' => $product->id]) }}" class="text-junie-navy hover:underline">Bảng giá đại lý →</a></div>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="inventory-heading">
                <h2 id="inventory-heading" class="text-xl font-semibold text-junie-navy">Theo dõi tồn kho</h2>
                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Theo dõi tồn</dt><dd class="mt-1 font-semibold text-junie-navy">{{ $product->track_inventory ? 'Có' : 'Không' }}</dd></div>
                    <div><dt class="text-slate-500">Ngưỡng tồn thấp</dt><dd class="mt-1 font-semibold text-junie-navy">@if ($product->default_low_stock_threshold !== null)<x-formatted-number :value="$product->default_low_stock_threshold" />@else — @endif</dd></div>
                </dl>
                <p class="mt-5 text-sm leading-6 text-slate-500">Số lượng thực tế được quản lý theo SKU và kho trong module Inventory hiện tại.</p>
                <a href="{{ route('admin.web.inventory.index', ['product_id' => $product->id]) }}" class="mt-3 inline-flex text-sm font-semibold text-junie-navy hover:underline">Quản lý tồn kho →</a>
            </section>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="images-heading">
            <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="images-heading" class="text-xl font-semibold text-junie-navy">Hình ảnh</h2>@if($product->status !== 'draft')<a href="{{ route('admin.web.products.edit', $product) }}#images-heading" class="text-sm font-semibold text-junie-navy hover:underline">Quản lý hình ảnh →</a>@elseif($product->wizard_owner_user_id === auth()->id())<a href="{{ route('admin.web.products.drafts.edit', $product) }}" class="text-sm font-semibold text-junie-navy hover:underline">Quản lý hình ảnh →</a>@endif</div>
            @if ($product->images->isEmpty())
                <p class="mt-4 text-sm text-slate-500">Sản phẩm chưa có hình ảnh.</p>
            @else
                <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($product->images as $image)
                        <figure class="min-w-0 overflow-hidden rounded-xl border border-slate-200"><x-product-image :image="$image" :alt="$product->name" class="aspect-square w-full" /><figcaption class="break-words p-3 text-xs text-slate-600">{{ $image->is_primary ? 'Ảnh chính' : 'Ảnh phụ' }}@if ($image->variant) · SKU {{ $image->variant->sku }}@endif</figcaption></figure>
                    @endforeach
                </div>
            @endif
        </section>

        <p class="text-xs text-slate-500">Tạo lúc {{ $product->created_at?->format('d/m/Y H:i') ?? '—' }} · Cập nhật {{ $product->updated_at?->format('d/m/Y H:i') ?? '—' }}</p>
    </div>
@endsection
