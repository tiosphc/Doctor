@extends('layouts.admin')

@section('title', $voucher ? 'Sửa voucher · '.$voucher->code : 'Tạo voucher')
@section('page-title', 'Voucher bán hàng')

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <div class="flex flex-wrap gap-4 text-sm font-semibold text-junie-navy"><a href="{{ route('admin.web.sales-vouchers.index') }}" class="underline">← Danh sách voucher</a>@if($voucher)<a href="{{ route('admin.web.sales-vouchers.show', $voucher) }}" class="underline">Chi tiết {{ $voucher->code }}</a>@endif</div>
    <header><h1 class="text-3xl font-semibold text-junie-navy">{{ $voucher ? 'Sửa voucher' : 'Tạo voucher' }}</h1><p class="mt-2 text-sm text-slate-600">Voucher này chỉ áp dụng cho đơn Retail. Checkout sẽ kiểm tra lại ngày hiệu lực, hạn mức và điều kiện trước khi redeem.</p></header>
    <form method="POST" action="{{ $voucher ? route('admin.web.sales-vouchers.update', $voucher) : route('admin.web.sales-vouchers.store') }}" x-data="{ submitting: false, type: @js(old('discount_type', $voucher?->discount_type ?? 'percentage')), status: @js(old('status', $voucher?->status ?? 'active')), originalStatus: @js($voucher?->status) }" @submit="if (submitting || (originalStatus && status !== originalStatus && !window.confirm('Xác nhận thay đổi trạng thái voucher?'))) { $event.preventDefault(); return; } submitting = true" class="space-y-5">
        @csrf
        @if($voucher)@method('PUT')@endif
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-junie-navy">Thông tin cơ bản</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><label for="code" class="block text-sm font-semibold">Mã voucher *</label><input id="code" name="code" required maxlength="80" value="{{ old('code', $voucher?->code ?? $suggestedCode) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-mono">@error('code')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="name" class="block text-sm font-semibold">Tên voucher *</label><input id="name" name="name" required maxlength="255" value="{{ old('name', $voucher?->name) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('name')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-2"><label for="description" class="block text-sm font-semibold">Mô tả</label><textarea id="description" name="description" rows="3" maxlength="5000" class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2">{{ old('description', $voucher?->description) }}</textarea>@error('description')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            </div>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-junie-navy">Giá trị và giới hạn</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div><label for="discount_type" class="block text-sm font-semibold">Kiểu giảm *</label><select id="discount_type" name="discount_type" x-model="type" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option value="percentage">Phần trăm</option><option value="fixed_amount">Số tiền cố định</option></select>@error('discount_type')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="discount_value" class="block text-sm font-semibold">Giá trị giảm *</label><input id="discount_value" name="discount_value" type="number" min="0.01" step="0.01" required value="{{ old('discount_value', $voucher?->discount_value) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3"><p class="mt-1 text-xs text-slate-500" x-text="type === 'percentage' ? 'Tối đa 100%.' : 'VND; service giới hạn không vượt tổng đơn.'"></p>@error('discount_value')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div x-show="type === 'percentage'"><label for="max_discount_amount" class="block text-sm font-semibold">Mức giảm tối đa (VND)</label><input id="max_discount_amount" name="max_discount_amount" type="number" min="0.01" step="0.01" value="{{ old('max_discount_amount', $voucher?->max_discount_amount) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('max_discount_amount')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="minimum_order_amount" class="block text-sm font-semibold">Đơn tối thiểu (VND)</label><input id="minimum_order_amount" name="minimum_order_amount" type="number" min="0" step="0.01" value="{{ old('minimum_order_amount', $voucher?->minimum_order_amount) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('minimum_order_amount')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="total_usage_limit" class="block text-sm font-semibold">Tổng lượt dùng</label><input id="total_usage_limit" name="total_usage_limit" type="number" min="1" step="1" value="{{ old('total_usage_limit', $voucher?->total_usage_limit) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('total_usage_limit')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="per_buyer_usage_limit" class="block text-sm font-semibold">Lượt mỗi khách</label><input id="per_buyer_usage_limit" name="per_buyer_usage_limit" type="number" min="1" step="1" value="{{ old('per_buyer_usage_limit', $voucher?->per_buyer_usage_limit) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('per_buyer_usage_limit')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            </div>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-junie-navy">Thời gian và trạng thái</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div><label for="starts_at" class="block text-sm font-semibold">Bắt đầu</label><input id="starts_at" name="starts_at" type="datetime-local" value="{{ old('starts_at', $voucher?->starts_at?->format('Y-m-d\TH:i')) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('starts_at')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="ends_at" class="block text-sm font-semibold">Kết thúc</label><input id="ends_at" name="ends_at" type="datetime-local" value="{{ old('ends_at', $voucher?->ends_at?->format('Y-m-d\TH:i')) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3">@error('ends_at')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="status" class="block text-sm font-semibold">Trạng thái lưu *</label><select id="status" name="status" x-model="status" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option value="active">Bật</option><option value="inactive">Tắt</option></select>@error('status')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            </div>
        </section>
        <div class="flex flex-wrap gap-3"><x-button type="submit" x-bind:disabled="submitting">{{ $voucher ? 'Lưu thay đổi' : 'Tạo voucher' }}</x-button><a href="{{ route('admin.web.sales-vouchers.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold">Hủy</a></div>
    </form>
</div>
@endsection
