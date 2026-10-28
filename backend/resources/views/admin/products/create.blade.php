@extends('layouts.admin')

@section('title', 'Thêm sản phẩm')
@section('page-title', 'Thêm sản phẩm')

@section('content')
<div class="mx-auto max-w-4xl space-y-6" x-data="{step: @js(collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'variants.')) ? 2 : (collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'images.')) ? 3 : 1)), rows: @js(old('variants', [['variant_name' => '', 'unit_id' => '', 'sellable_retail' => true, 'sellable_dealer' => false]])), errors: @js($errors->toArray()), imageNames: [], init() { this.rows = this.rows.map(row => ({...row, sellable_retail: row.sellable_retail === true || row.sellable_retail === '1', sellable_dealer: row.sellable_dealer === true || row.sellable_dealer === '1'})); }}">
    <a href="{{ route('admin.web.products.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Danh sách sản phẩm</a>
    <header><h1 class="text-2xl font-semibold text-junie-navy">Tạo sản phẩm</h1><p class="mt-2 text-sm text-slate-600">Thông tin → SKU / biến thể → Ảnh → Kiểm tra và lưu. Giá và tồn thực tế được quản lý trong các module riêng.</p></header>
    <nav aria-label="Các bước tạo sản phẩm" class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach(['Thông tin', 'SKU / Biến thể', 'Hình ảnh', 'Xem lại'] as $index => $label)
            <button type="button" @click="step = {{ $index + 1 }}" :aria-current="step === {{ $index + 1 }} ? 'step' : null" :class="step === {{ $index + 1 }} ? 'bg-junie-navy text-white' : 'bg-white text-junie-navy'" class="min-h-11 rounded-lg border border-slate-300 px-3 text-sm font-semibold">{{ $index + 1 }}. {{ $label }}</button>
        @endforeach
    </nav>
    <form action="{{ route('admin.web.products.store') }}" method="POST" enctype="multipart/form-data" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" novalidate>
        @csrf
        <section x-show="step === 1" aria-label="Thông tin sản phẩm">
            <h2 class="mb-5 text-lg font-semibold text-junie-navy">Thông tin cơ bản</h2>
            @include('admin.products.form-fields', ['product' => null])
            <div class="mt-5 max-w-sm"><label for="base_sku" class="block text-sm font-semibold text-slate-700">SKU gốc *</label><input id="base_sku" name="base_sku" required maxlength="100" value="{{ old('base_sku') }}" placeholder="VD: CERA" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-mono"><p class="mt-1 text-xs text-slate-500">Mã SKU của từng biến thể sẽ được tạo từ SKU gốc và tên biến thể.</p>@error('base_sku')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            @if($categories->isEmpty() || $units->isEmpty())<p class="mt-4 text-sm text-amber-700">Cần có danh mục và đơn vị đang hoạt động trước khi tạo sản phẩm. @if($units->isEmpty())<a href="{{ route('admin.web.units.index') }}" class="font-semibold underline">Quản lý đơn vị</a>@endif</p>@endif
        </section>
        <section x-show="step === 2" aria-label="SKU và biến thể">
            <h2 class="text-lg font-semibold text-junie-navy">SKU / Biến thể</h2>
            <a href="{{ route('admin.web.units.index') }}" class="mt-1 inline-block text-sm font-semibold text-junie-navy hover:underline">Quản lý đơn vị →</a>
            <p class="mt-1 text-sm text-slate-600">Mỗi biến thể có một đơn vị riêng. SKU được tạo từ SKU gốc và tên biến thể bằng cùng quy tắc với wizard hiện tại.</p>
            @error('variants')<p class="mt-3 text-sm text-red-700">{{ $message }}</p>@enderror
            <div class="mt-5 space-y-4">
                <template x-for="(row, index) in rows" :key="index">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="mb-4 flex items-center justify-between"><h3 class="font-semibold text-junie-navy" x-text="'Biến thể ' + (index + 1)"></h3><button type="button" x-show="rows.length > 1" @click="rows.splice(index, 1)" class="text-sm font-semibold text-red-700">Xóa dòng</button></div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div><label :for="'variant-name-'+index" class="block text-sm font-semibold">Tên biến thể *</label><input :id="'variant-name-'+index" :name="'variants['+index+'][variant_name]'" x-model="row.variant_name" required class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><p x-show="errors['variants.'+index+'.variant_name']" x-text="errors['variants.'+index+'.variant_name']?.[0]" class="mt-1 text-sm text-red-700"></p></div>
                            <div class="text-sm"><span class="block font-semibold">SKU</span><p class="mt-2 min-h-11 rounded-lg border border-slate-200 bg-white px-3 py-3 text-slate-600">Tự tạo khi lưu</p><p x-show="errors['variants.'+index+'.sku']" x-text="errors['variants.'+index+'.sku']?.[0]" class="mt-1 text-sm text-red-700"></p></div>
                            <div><label :for="'variant-unit-'+index" class="block text-sm font-semibold">Đơn vị *</label><select :id="'variant-unit-'+index" :name="'variants['+index+'][unit_id]'" x-model="row.unit_id" required class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Chọn đơn vị</option>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->symbol }})</option>@endforeach</select><p x-show="errors['variants.'+index+'.unit_id']" x-text="errors['variants.'+index+'.unit_id']?.[0]" class="mt-1 text-sm text-red-700"></p></div>
                            <div class="flex flex-wrap items-center gap-4"><label class="flex items-center gap-2 text-sm"><input type="hidden" :name="'variants['+index+'][sellable_retail]'" value="0"><input type="checkbox" :name="'variants['+index+'][sellable_retail]'" value="1" x-model="row.sellable_retail">Bán Retail</label><label class="flex items-center gap-2 text-sm"><input type="hidden" :name="'variants['+index+'][sellable_dealer]'" value="0"><input type="checkbox" :name="'variants['+index+'][sellable_dealer]'" value="1" x-model="row.sellable_dealer">Bán đại lý</label><p x-show="errors['variants.'+index+'.sellable_retail']" x-text="errors['variants.'+index+'.sellable_retail']?.[0]" class="text-sm text-red-700"></p></div>
                        </div>
                    </div>
                </template>
            </div>
            <button type="button" @click="if(rows.length < 100) rows.push({variant_name: '', unit_id: '', sellable_retail: true, sellable_dealer: false})" class="mt-4 min-h-11 rounded-lg border border-junie-navy px-4 text-sm font-semibold text-junie-navy">+ Thêm biến thể</button>
        </section>
        <section x-show="step === 3" aria-label="Hình ảnh sản phẩm">
            <h2 class="text-lg font-semibold text-junie-navy">Hình ảnh</h2><p class="mt-2 text-sm text-slate-600">JPG, PNG hoặc WebP, tối đa 5 MB mỗi ảnh. Ảnh đầu tiên sẽ là ảnh chính.</p>
            <label for="images" class="mt-5 block text-sm font-semibold">Chọn ảnh</label><input id="images" name="images[]" type="file" multiple accept="image/jpeg,image/png,image/webp" @change="imageNames = Array.from($event.target.files).map(file => file.name)" class="mt-2 block w-full rounded-lg border border-slate-300 p-3 text-sm">
            <ul class="mt-3 list-inside list-disc text-sm text-slate-600"><template x-for="name in imageNames"><li x-text="name"></li></template></ul>
            @error('images.*')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        </section>
        <section x-show="step === 4" aria-label="Xem lại">
            <h2 class="text-lg font-semibold text-junie-navy">Xem lại và lưu</h2><p class="mt-2 text-sm text-slate-600">Hãy kiểm tra thông tin, các SKU và ảnh trước khi lưu. Laravel sẽ kiểm tra toàn bộ form và giữ dữ liệu nếu có lỗi.</p>
            <p class="mt-4 text-sm font-semibold" x-text="rows.length + ' SKU / biến thể'"></p>
            <ul class="mt-2 list-inside list-disc text-sm text-slate-600"><template x-for="row in rows"><li x-text="row.variant_name || 'Chưa đặt tên'"></li></template></ul>
        </section>
        <div class="mt-6 flex flex-wrap justify-between gap-3 border-t border-slate-200 pt-5"><button type="button" x-show="step > 1" @click="step--" class="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold">Quay lại</button><span x-show="step === 1"></span><button type="button" x-show="step < 4" @click="step++" class="min-h-11 rounded-lg bg-junie-navy px-4 text-sm font-semibold text-white">Tiếp tục</button><button type="submit" x-show="step === 4" class="min-h-11 rounded-lg bg-junie-navy px-4 text-sm font-semibold text-white">Lưu sản phẩm</button></div>
    </form>
</div>
@endsection
