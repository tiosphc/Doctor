@extends('layouts.admin')

@section('title', 'Thao tác tồn kho')
@section('page-title', 'Thao tác tồn kho')

@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <a href="{{ route('admin.web.inventory.show', $variant) }}" class="text-sm font-semibold text-junie-navy hover:underline">← Chi tiết SKU {{ $variant->sku }}</a>
    <header><p class="text-sm text-slate-500">{{ $variant->product->name }} · {{ $warehouse->code }} · {{ $warehouse->name }}</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">{{ match($mode) { 'opening' => 'Thiết lập tồn đầu kỳ', 'adjustment' => 'Điều chỉnh tồn kho', 'receipt' => 'Nhập kho thủ công' } }}</h1><p class="mt-2 break-all font-mono text-sm text-slate-600">{{ $variant->sku }} · {{ $variant->unit?->symbol }}</p></header>
    <div class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 text-sm sm:grid-cols-3"><div><span class="block text-slate-500">Tồn thực tế</span><strong class="mt-1 block text-lg"><x-formatted-number :value="$balance?->on_hand_quantity ?? '0'" /></strong></div><div><span class="block text-slate-500">Đã giữ</span><strong class="mt-1 block text-lg"><x-formatted-number :value="$balance?->reserved_quantity ?? '0'" /></strong></div><div><span class="block text-slate-500">Khả dụng</span><strong class="mt-1 block text-lg"><x-formatted-number :value="$balance?->available_quantity ?? '0'" /></strong></div></div>
    @error('inventory')<x-alert tone="error">{{ $message }}</x-alert>@enderror
    @if($openingRecorded)
        <x-alert tone="warning">SKU này đã có lịch sử hoặc số dư tại kho, nên không thể ghi tồn đầu kỳ lần nữa. Hãy dùng điều chỉnh nếu cần sửa chênh lệch.</x-alert>
    @endif
    @if($warehouse->status !== 'active' || $variant->status !== 'active' || !$variant->track_inventory || $variant->product->status === 'inactive')
        <x-alert tone="warning">Kho hoặc SKU không còn đủ điều kiện ghi tồn.</x-alert>
    @elseif(!$openingRecorded)
        <form method="POST" action="{{ route('admin.web.inventory.'.$mode.'.store', [$variant, $warehouse]) }}" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" x-data="{ quantity: @js(old('quantity', '')), reference: @js(old('reference_id', '')), confirm: false, submitted: false }" @submit="if (submitted) { $event.preventDefault() } else { submitted = true }">
            @csrf
            <input type="hidden" name="product_variant_id" value="{{ $variant->id }}"><input type="hidden" name="warehouse_id" value="{{ $warehouse->id }}"><input type="hidden" name="operation_key" value="{{ $operationKey }}">
            <p class="text-sm text-slate-600">@if($mode === 'opening') Chỉ ghi một lần cho từng SKU và kho. Có thể ghi số lượng 0; thao tác vẫn tạo movement. @elseif($mode === 'adjustment') Nhập số chênh lệch: dương để tăng, âm để giảm. Không nhập số tồn mục tiêu. @else Chỉ dùng cho hàng vào kho ngoài đơn mua hàng. Hàng từ nhà cung cấp phải qua Goods Receipt. @endif</p>
            <label class="block text-sm font-semibold text-slate-700">Số lượng {{ $mode === 'adjustment' ? '(+ / −)' : '' }}<input name="quantity" type="number" step="1" @if($mode !== 'adjustment') min="{{ $mode === 'opening' ? 0 : 1 }}" @endif required x-model="quantity" value="{{ old('quantity') }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3" placeholder="{{ $mode === 'adjustment' ? '-2 hoặc 2' : '0' }}"></label>
            @error('quantity')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            @if($mode !== 'opening')
                <label class="block text-sm font-semibold text-slate-700">Lý do<select name="reason_code" required class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Chọn lý do</option>
                    @foreach(($mode === 'adjustment' ? ['COUNT_CORRECTION' => 'Kiểm kê chênh lệch', 'DAMAGED' => 'Hàng hư hỏng', 'EXPIRED' => 'Hàng hết hạn', 'LOST' => 'Mất hàng', 'DATA_CORRECTION' => 'Sai dữ liệu', 'OTHER' => 'Khác'] : ['SAMPLE' => 'Hàng mẫu', 'GIFT' => 'Hàng tặng', 'SPECIAL_INBOUND' => 'Nhập bổ sung đặc biệt', 'MIGRATION' => 'Dữ liệu chuyển đổi', 'OTHER' => 'Khác']) as $code => $label)
                        <option value="{{ $code }}" @selected(old('reason_code') === $code)>{{ $label }}</option>
                    @endforeach
                </select></label>
                @error('reason_code')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <label class="block text-sm font-semibold text-slate-700">Mã tham chiếu (nếu có)<input name="reference_id" x-model="reference" value="{{ old('reference_id') }}" maxlength="100" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3" placeholder="Phiếu nội bộ"></label><input type="hidden" name="reference_type" value="MANUAL" :disabled="reference.trim() === ''">
                @error('reference_id')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            @endif
            <label class="block text-sm font-semibold text-slate-700">Ghi chú {{ $mode === 'receipt' ? '(nếu có)' : '*' }}<textarea name="reason_detail" rows="3" maxlength="2000" @if($mode !== 'receipt') required @endif class="mt-2 w-full rounded-lg border border-slate-300 p-3">{{ old('reason_detail') }}</textarea></label>
            @error('reason_detail')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            <div x-show="!confirm"><x-button type="button" x-on:click="if ($el.closest('form').reportValidity()) confirm = true">Kiểm tra trước khi ghi</x-button></div>
            <div x-cloak x-show="confirm" class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                <strong class="block">Xác nhận ghi tồn kho</strong><p>SKU: {{ $variant->sku }} · Kho: {{ $warehouse->code }}</p><p>Tồn hiện tại: <x-formatted-number :value="$balance?->on_hand_quantity ?? '0'" /> · Thay đổi: <span x-text="quantity"></span></p><p>Hệ thống sẽ kiểm tra lại số dư và lượng đã giữ trước khi ghi.</p>
                <div class="flex flex-wrap gap-3"><x-button type="submit" x-bind:disabled="submitted">Xác nhận</x-button><button type="button" x-on:click="confirm = false" class="font-semibold text-junie-navy">Sửa lại</button></div>
            </div>
        </form>
    @endif
</div>
@endsection
