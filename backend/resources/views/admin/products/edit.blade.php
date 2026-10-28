@extends('layouts.admin')

@section('title', 'Sửa '.$product->name)
@section('page-title', 'Sửa sản phẩm')

@section('content')
<div class="mx-auto max-w-5xl space-y-7">
    <div class="flex flex-wrap items-center justify-between gap-3"><a href="{{ route('admin.web.products.show', $product) }}" class="text-sm font-semibold text-junie-navy hover:underline">← Chi tiết sản phẩm</a><span class="font-mono text-xs text-slate-500">{{ $product->product_code }}</span></div>
    <header><h1 class="text-2xl font-semibold text-junie-navy">{{ $product->name }}</h1><p class="mt-2 text-sm text-slate-600">Sản phẩm, SKU và ảnh. Giá bán và tồn kho được quản lý ở module riêng.</p></header>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="product-form-heading">
        <h2 id="product-form-heading" class="mb-5 text-xl font-semibold text-junie-navy">Thông tin sản phẩm</h2>
        <form method="POST" action="{{ route('admin.web.products.update', $product) }}">
            @csrf @method('PATCH')
            @include('admin.products.form-fields', ['product' => $product])
            <div class="mt-6 flex justify-end"><button type="submit" class="min-h-11 rounded-lg bg-junie-navy px-4 text-sm font-semibold text-white">Lưu thông tin</button></div>
        </form>
    </section>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="variants-heading">
        <h2 id="variants-heading" class="text-xl font-semibold text-junie-navy">SKU / Biến thể</h2>
        <p class="mt-1 text-sm text-slate-600">SKU và đơn vị chỉ đổi được khi chưa có lịch sử kho. Ngừng hoạt động SKU để giữ lịch sử ERP.</p>
        <div class="mt-5 grid gap-4 lg:grid-cols-2">
            @foreach($product->variants as $variant)
                <form method="POST" action="{{ route('admin.web.products.variants.update', [$product, $variant]) }}" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    @csrf @method('PATCH')
                    <h3 class="mb-4 break-all font-mono text-sm font-semibold text-junie-navy">{{ $variant->sku }}</h3>
                    @error('variant_'.$variant->id)<p class="mb-3 text-sm text-red-700">{{ $message }}</p>@enderror
                    @include('admin.products.variant-fields', ['variant' => $variant, 'prefix' => 'edit-'.$variant->id])
                    <button type="submit" class="mt-4 min-h-11 rounded-lg border border-junie-navy px-4 text-sm font-semibold text-junie-navy">Lưu SKU</button>
                </form>
            @endforeach
        </div>
        <form method="POST" action="{{ route('admin.web.products.variants.store', $product) }}" class="mt-6 rounded-xl border border-dashed border-slate-300 p-4">
            @csrf
            <h3 class="mb-4 font-semibold text-junie-navy">Thêm SKU / biến thể</h3>
            @include('admin.products.variant-fields', ['variant' => null, 'prefix' => 'new'])
            <button type="submit" class="mt-4 min-h-11 rounded-lg bg-junie-navy px-4 text-sm font-semibold text-white">Thêm SKU</button>
        </form>
    </section>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="images-heading">
        <h2 id="images-heading" class="text-xl font-semibold text-junie-navy">Hình ảnh</h2>
        <p class="mt-1 text-sm text-slate-600">JPG, PNG hoặc WebP tối đa 5 MB. Có thể đặt ảnh chính, liên kết SKU và chỉnh thứ tự.</p>
        <form method="POST" action="{{ route('admin.web.products.images.store', $product) }}" enctype="multipart/form-data" class="mt-5 grid gap-4 rounded-xl border border-dashed border-slate-300 p-4 sm:grid-cols-2">
            @csrf
            <div><label for="new-image" class="block text-sm font-semibold">Ảnh mới *</label><input id="new-image" name="image" type="file" required accept="image/jpeg,image/png,image/webp" class="mt-2 w-full rounded-lg border border-slate-300 p-2 text-sm">@error('image')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            <div><label for="new-image-variant" class="block text-sm font-semibold">SKU liên kết</label><select id="new-image-variant" name="product_variant_id" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Ảnh chung</option>@foreach($product->variants as $variant)<option value="{{ $variant->id }}">{{ $variant->sku }}</option>@endforeach</select></div>
            <div><label for="new-image-alt" class="block text-sm font-semibold">Mô tả ảnh</label><input id="new-image-alt" name="alt_text" maxlength="255" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"></div>
            <div><label for="new-image-sort" class="block text-sm font-semibold">Thứ tự</label><input id="new-image-sort" name="sort_order" type="number" min="0" value="{{ $product->images->max('sort_order') + 1 }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"></div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_primary" value="1">Đặt làm ảnh chính</label>
            <button type="submit" class="min-h-11 rounded-lg bg-junie-navy px-4 text-sm font-semibold text-white">Tải ảnh lên</button>
        </form>
        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($product->images as $image)
                <div class="overflow-hidden rounded-xl border border-slate-200">
                    <x-product-image :image="$image" :alt="$image->alt_text ?: $product->name" class="aspect-square w-full" />
                    <div class="space-y-3 p-3">
                        <p class="text-sm font-semibold text-junie-navy">{{ $image->is_primary ? 'Ảnh chính' : 'Ảnh phụ' }}@if($image->variant) · {{ $image->variant->sku }}@endif</p>
                        <form method="POST" action="{{ route('admin.web.products.images.update', [$product, $image]) }}" class="space-y-3">
                            @csrf @method('PATCH')
                            <div><label for="image-alt-{{ $image->id }}" class="block text-xs font-semibold">Mô tả</label><input id="image-alt-{{ $image->id }}" name="alt_text" value="{{ $image->alt_text }}" class="mt-1 min-h-10 w-full rounded-lg border border-slate-300 px-2 text-sm"></div>
                            <div><label for="image-sort-{{ $image->id }}" class="block text-xs font-semibold">Thứ tự</label><input id="image-sort-{{ $image->id }}" type="number" min="0" name="sort_order" value="{{ $image->sort_order }}" class="mt-1 min-h-10 w-full rounded-lg border border-slate-300 px-2 text-sm"></div>
                            <div><label for="image-variant-{{ $image->id }}" class="block text-xs font-semibold">SKU liên kết</label><select id="image-variant-{{ $image->id }}" name="product_variant_id" class="mt-1 min-h-10 w-full rounded-lg border border-slate-300 px-2 text-sm"><option value="">Ảnh chung</option>@foreach($product->variants as $variant)<option value="{{ $variant->id }}" @selected($image->product_variant_id === $variant->id)>{{ $variant->sku }}</option>@endforeach</select></div>
                            @unless($image->is_primary)<label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_primary" value="1">Đặt làm ảnh chính</label>@endunless
                            <button type="submit" class="min-h-10 rounded-lg border border-junie-navy px-3 text-xs font-semibold text-junie-navy">Lưu ảnh</button>
                        </form>
                        <form method="POST" action="{{ route('admin.web.products.images.destroy', [$product, $image]) }}" onsubmit="return confirm('Xóa ảnh này?')">@csrf @method('DELETE')<button type="submit" class="min-h-10 text-xs font-semibold text-red-700">Xóa ảnh</button></form>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>
@endsection
