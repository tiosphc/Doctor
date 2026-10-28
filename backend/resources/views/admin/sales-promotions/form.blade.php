@extends('layouts.admin')

@section('title', $promotion ? 'Sửa khuyến mãi · '.$promotion->code : 'Tạo khuyến mãi')
@section('page-title', 'Khuyến mãi bán hàng')

@section('content')
@php
    $gift = $promotion?->giftRule;
    $editor = [
        'endpoint' => route('admin.web.sales-promotions.options'),
        'scope' => old('sales_scope', $promotion?->sales_scope ?? 'retail'),
        'type' => old('discount_type', $promotion?->discount_type ?? 'percentage'),
        'status' => old('status', $promotion?->status ?? 'active'),
        'originalStatus' => $promotion?->status,
        'selectedProducts' => $selectedProducts,
        'selectedCategories' => $selectedCategories,
        'buyProduct' => $buyProduct,
        'giftProduct' => $giftProduct,
        'buyVariant' => $buyVariant,
        'giftVariant' => $giftVariant,
    ];
    $selectedTierIds = array_map('intval', old('dealer_tier_ids', $promotion?->dealerTiers->pluck('id')->all() ?? []));
@endphp
<div class="mx-auto max-w-6xl space-y-6">
    <div class="flex flex-wrap gap-4 text-sm font-semibold text-junie-navy"><a href="{{ route('admin.web.sales-promotions.index') }}" class="underline">← Danh sách khuyến mãi</a>@if($promotion)<a href="{{ route('admin.web.sales-promotions.show', $promotion) }}" class="underline">Chi tiết {{ $promotion->code }}</a>@endif</div>
    <header><h1 class="text-3xl font-semibold text-junie-navy">{{ $promotion ? 'Sửa khuyến mãi' : 'Tạo khuyến mãi' }}</h1><p class="mt-2 text-sm text-slate-600">Ưu đãi giảm giá và Mua A tặng A/B dùng cùng contract Sales Promotion. Giỏ hàng và Checkout sẽ tự kiểm tra điều kiện, tồn kho và hạn mức.</p></header>
    <form method="POST" action="{{ $promotion ? route('admin.web.sales-promotions.update', $promotion) : route('admin.web.sales-promotions.store') }}" data-editor="{{ json_encode($editor, JSON_THROW_ON_ERROR) }}" x-data="promotionEditor(JSON.parse($el.dataset.editor))" @submit="if (submitting || (originalStatus && status !== originalStatus && !window.confirm('Xác nhận thay đổi trạng thái khuyến mãi?'))) { $event.preventDefault(); return; } submitting = true" class="space-y-5">
        @csrf
        @if($promotion)@method('PATCH')@endif
        @error('operation')<x-alert tone="error">{{ $message }}</x-alert>@enderror
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-junie-navy">Thông tin cơ bản</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><label for="code" class="block text-sm font-semibold">Mã chương trình *</label><input id="code" name="code" required maxlength="80" value="{{ old('code', $promotion?->code ?? $suggestedCode) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-mono">@error('code')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="name" class="block text-sm font-semibold">Tên chương trình *</label><input id="name" name="name" required maxlength="255" value="{{ old('name', $promotion?->name) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('name')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-2"><label for="description" class="block text-sm font-semibold">Mô tả</label><textarea id="description" name="description" rows="3" maxlength="5000" class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2">{{ old('description', $promotion?->description) }}</textarea>@error('description')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            </div>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-junie-navy">Loại ưu đãi và đối tượng</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><label for="discount_type" class="block text-sm font-semibold">Kiểu ưu đãi *</label><select id="discount_type" name="discount_type" x-model="type" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option value="percentage">Giảm phần trăm</option><option value="fixed_amount">Giảm số tiền mỗi đơn vị sản phẩm</option><option value="buy_a_get_b">Mua A tặng A/B</option></select>@error('discount_type')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="sales_scope" class="block text-sm font-semibold">Kênh bán *</label><select id="sales_scope" name="sales_scope" x-model="scope" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option value="retail">Retail</option><option value="dealer">Dealer</option><option value="both">Retail và Dealer</option></select>@error('sales_scope')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            </div>
            <p class="mt-3 text-xs text-slate-500">Giảm cố định là contract Sales Promotion hiện có; Voucher Retail được quản lý ở màn hình riêng.</p>
        </section>
        <template x-if="type !== 'buy_a_get_b'"><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-junie-navy">Giảm giá sản phẩm</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div><label for="discount_value" class="block text-sm font-semibold">Giá trị giảm *</label><input id="discount_value" name="discount_value" type="number" min="0.01" step="0.01" required value="{{ old('discount_value', $promotion?->discount_value) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3"><p class="mt-1 text-xs text-slate-500" x-text="type === 'percentage' ? 'Phần trăm, tối đa 100.' : 'VND trên mỗi đơn vị đủ điều kiện.'"></p>@error('discount_value')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="max_discount_amount" class="block text-sm font-semibold">Mức giảm tối đa (VND)</label><input id="max_discount_amount" name="max_discount_amount" type="number" min="0.01" step="0.01" value="{{ old('max_discount_amount', $promotion?->max_discount_amount) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('max_discount_amount')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="minimum_order_amount" class="block text-sm font-semibold">Đơn tối thiểu (VND)</label><input id="minimum_order_amount" name="minimum_order_amount" type="number" min="0" step="0.01" value="{{ old('minimum_order_amount', $promotion?->minimum_order_amount) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('minimum_order_amount')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            </div>
            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                @include('admin.sales-promotions.multiple-picker', ['kind' => 'selectedProducts', 'field' => 'product_ids', 'label' => 'Sản phẩm áp dụng', 'errorKey' => 'product_ids'])
                <template x-if="scope === 'dealer'"><div>@include('admin.sales-promotions.multiple-picker', ['kind' => 'selectedCategories', 'field' => 'category_ids', 'label' => 'Danh mục Dealer (nếu có)', 'errorKey' => 'category_ids'])</div></template>
            </div>
            <p class="mt-3 text-xs text-slate-500">Retail và Retail + Dealer yêu cầu chọn ít nhất một Product. Service kiểm tra xung đột ưu đãi đang hiệu lực.</p>
        </section></template>
        <template x-if="type === 'buy_a_get_b'"><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <input type="hidden" name="discount_value" value="0">
            <h2 class="text-lg font-semibold text-junie-navy">Điều kiện quà tặng A/B</h2><p class="mt-2 text-sm text-slate-600">Có thể tặng lại chính Product A hoặc chọn Product B được phép làm quà. Chỉ SKU đang hoạt động và theo dõi tồn kho được chấp nhận.</p>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                @include('admin.sales-promotions.single-picker', ['kind' => 'buyProduct', 'field' => 'gift_rule[buy_product_id]', 'label' => 'Product mua (A) *', 'errorKey' => 'gift_rule.buy_product_id'])
                @include('admin.sales-promotions.single-picker', ['kind' => 'giftProduct', 'field' => 'gift_rule[gift_product_id]', 'label' => 'Product tặng (A hoặc B) *', 'errorKey' => 'gift_rule.gift_product_id'])
                @include('admin.sales-promotions.single-picker', ['kind' => 'buyVariant', 'field' => 'gift_rule[buy_variant_id]', 'label' => 'SKU mua (để trống: mọi SKU hợp lệ)', 'errorKey' => 'gift_rule.buy_variant_id'])
                @include('admin.sales-promotions.single-picker', ['kind' => 'giftVariant', 'field' => 'gift_rule[gift_variant_id]', 'label' => 'SKU tặng *', 'errorKey' => 'gift_rule.gift_variant_id'])
                <div><label for="minimum_buy_quantity" class="block text-sm font-semibold">Số lượng mua tối thiểu *</label><input id="minimum_buy_quantity" name="gift_rule[minimum_buy_quantity]" type="number" min="1" step="1" required value="{{ old('gift_rule.minimum_buy_quantity', $gift?->minimum_buy_quantity ?? 1) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('gift_rule.minimum_buy_quantity')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="gift_quantity" class="block text-sm font-semibold">Số lượng quà *</label><input id="gift_quantity" name="gift_rule[gift_quantity]" type="number" min="1" step="1" required value="{{ old('gift_rule.gift_quantity', $gift?->gift_quantity ?? 1) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('gift_rule.gift_quantity')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            </div>
            <label class="mt-4 flex items-center gap-2 text-sm font-semibold"><input type="hidden" name="gift_rule[repeat_per_multiple]" value="0"><input type="checkbox" name="gift_rule[repeat_per_multiple]" value="1" @checked(old('gift_rule.repeat_per_multiple', $gift?->repeat_per_multiple ?? false)) class="size-4 rounded border-slate-300">Tặng theo mỗi bội số lượng mua</label>
            @error('gift_rule')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        </section></template>
        <div x-show="scope !== 'retail'" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Tier đại lý (nếu giới hạn)</h2><div class="mt-3 flex flex-wrap gap-4">@foreach($tiers as $tier)<label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="dealer_tier_ids[]" value="{{ $tier->id }}" @checked(in_array($tier->id, $selectedTierIds, true)) class="size-4 rounded border-slate-300">{{ $tier->name }}</label>@endforeach</div>@error('dealer_tier_ids')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Hiệu lực và hạn mức</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div><label for="starts_at" class="block text-sm font-semibold">Bắt đầu</label><input id="starts_at" name="starts_at" type="datetime-local" value="{{ old('starts_at', $promotion?->starts_at?->format('Y-m-d\TH:i')) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('starts_at')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="ends_at" class="block text-sm font-semibold">Kết thúc</label><input id="ends_at" name="ends_at" type="datetime-local" value="{{ old('ends_at', $promotion?->ends_at?->format('Y-m-d\TH:i')) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('ends_at')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="status" class="block text-sm font-semibold">Trạng thái lưu *</label><select id="status" name="status" x-model="status" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option value="active">Bật</option><option value="inactive">Tắt</option></select>@error('status')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="total_usage_limit" class="block text-sm font-semibold">Tổng lượt dùng</label><input id="total_usage_limit" name="total_usage_limit" type="number" min="1" step="1" value="{{ old('total_usage_limit', $promotion?->total_usage_limit) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('total_usage_limit')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="per_buyer_usage_limit" class="block text-sm font-semibold">Lượt mỗi người mua/đại lý</label><input id="per_buyer_usage_limit" name="per_buyer_usage_limit" type="number" min="1" step="1" value="{{ old('per_buyer_usage_limit', $promotion?->per_buyer_usage_limit) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('per_buyer_usage_limit')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            </div>
        </section>
        <div class="flex flex-wrap gap-3"><x-button type="submit" x-bind:disabled="submitting">{{ $promotion ? 'Lưu thay đổi' : 'Tạo khuyến mãi' }}</x-button><a href="{{ route('admin.web.sales-promotions.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold">Hủy</a></div>
    </form>
</div>
@endsection
