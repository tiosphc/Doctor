@php($isCreate = $product === null)
<div class="grid gap-5 sm:grid-cols-2">
    <div>
        <label for="name" class="block text-sm font-semibold text-slate-700">Tên sản phẩm *</label>
        <input id="name" name="name" required maxlength="255" value="{{ old('name', $product?->name) }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3">
        @error('name')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="slug" class="block text-sm font-semibold text-slate-700">Slug *</label>
        <input id="slug" name="slug" required maxlength="255" value="{{ old('slug', $product?->slug) }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3">
        @error('slug')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
    </div>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-2"><label for="product_category_id" class="block text-sm font-semibold text-slate-700">Danh mục *</label><a href="{{ route('admin.web.categories.index') }}" class="text-xs font-semibold text-junie-navy hover:underline">Quản lý danh mục</a></div>
        <select id="product_category_id" name="product_category_id" required class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3">
            <option value="">Chọn danh mục</option>
            @foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old('product_category_id', $product?->product_category_id) === (string) $category->id)>{{ $category->name }}{{ $category->status === 'inactive' ? ' (ngừng hoạt động, đang sử dụng)' : '' }}</option>@endforeach
        </select>
        @error('product_category_id')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
    </div>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-2"><label for="brand_id" class="block text-sm font-semibold text-slate-700">Thương hiệu</label><a href="{{ route('admin.web.brands.index') }}" class="text-xs font-semibold text-junie-navy hover:underline">Quản lý thương hiệu</a></div>
        <select id="brand_id" name="brand_id" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3">
            <option value="">Không chọn</option>
            @foreach($brands as $brand)<option value="{{ $brand->id }}" @selected((string) old('brand_id', $product?->brand_id) === (string) $brand->id)>{{ $brand->name }}{{ $brand->status === 'inactive' ? ' (ngừng hoạt động, đang sử dụng)' : '' }}</option>@endforeach
        </select>
        @error('brand_id')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="status" class="block text-sm font-semibold text-slate-700">Trạng thái</label>
        <select id="status" name="status" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3">
            <option value="active" @selected(old('status', $product?->status ?? 'active') === 'active')>Hoạt động</option>
            <option value="inactive" @selected(old('status', $product?->status) === 'inactive')>Ngừng hoạt động</option>
        </select>
    </div>
    <div>
        <label for="default_low_stock_threshold" class="block text-sm font-semibold text-slate-700">Ngưỡng tồn thấp</label>
        <input id="default_low_stock_threshold" type="number" min="0" name="default_low_stock_threshold" value="{{ old('default_low_stock_threshold', $product?->default_low_stock_threshold) }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3">
        @error('default_low_stock_threshold')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label for="description" class="block text-sm font-semibold text-slate-700">Mô tả</label>
        <textarea id="description" name="description" rows="4" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('description', $product?->description) }}</textarea>
        @error('description')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
    </div>
</div>
<div class="mt-5 grid gap-3 sm:grid-cols-2">
    @foreach(['track_inventory' => 'Theo dõi tồn kho', 'track_batch' => 'Theo dõi lô', 'track_expiry' => 'Theo dõi hạn dùng', 'can_be_gift' => 'Có thể làm quà', 'gift_only' => 'Chỉ dùng làm quà'] as $field => $label)
        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="hidden" name="{{ $field }}" value="0"><input type="checkbox" name="{{ $field }}" value="1" @checked((bool) old($field, $product?->{$field} ?? ($field === 'track_inventory' && $isCreate))) class="rounded border-slate-300">{{ $label }}</label>
    @endforeach
</div>
