@extends('layouts.admin')

@section('title', 'Tạo phiếu trả hàng')
@section('page-title', 'Tạo phiếu trả hàng')

@section('content')
<div class="max-w-3xl space-y-6">
    <a href="{{ route('admin.web.sales.orders.show', $order) }}" class="text-sm font-semibold text-junie-navy hover:underline">← {{ $order->order_code }}</a>
    <header><h1 class="text-3xl font-semibold text-junie-navy">Ghi nhận hàng trả</h1><p class="mt-2 text-sm text-slate-600">{{ $order->recipient_name }} · {{ ucfirst($order->sales_channel) }} · Kho {{ $order->warehouse?->name }}. Phiếu sẽ chờ kiểm hàng; chưa tăng tồn kho hoặc hoàn tiền.</p></header>
    @if ($errors->any())<div class="space-y-2">@foreach ($errors->all() as $message)<x-alert tone="error">{{ $message }}</x-alert>@endforeach</div>@endif
    @if ($returnableItems->isEmpty())
        <x-empty-state title="Đơn chưa có sản phẩm đủ số lượng để trả." />
    @else
        <form method="POST" action="{{ route('admin.web.sales.returns.store', $order) }}" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
            @csrf
            <input type="hidden" name="operation_key" value="{{ $operationKey }}"><input type="hidden" name="processing_mode" value="pending_inspection">
            <fieldset class="space-y-3"><legend class="text-sm font-semibold">Sản phẩm đã xuất · chọn một hoặc nhiều dòng</legend>
                @foreach ($returnableItems as $item)
                    @php $selectedRow = collect(old('items', []))->firstWhere('item_id', $item['item_id']); @endphp
                    <div class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-[minmax(0,1fr)_9rem]" x-data="{ selected: @js($selectedRow !== null) }">
                        <label class="flex items-start gap-3"><input type="checkbox" x-model="selected" class="mt-1 size-4"><span><strong>{{ $item['product_name'] }}</strong><span class="block text-xs text-slate-600">{{ $item['sku'] }} · Đã xuất {{ $item['fulfilled_quantity'] }} · Đã trả {{ $item['returned_quantity'] }} · Đang xử lý {{ $item['pending_quantity'] }} · Còn được trả {{ $item['returnable_quantity'] }} {{ $item['unit_code'] }}</span></span></label>
                        <label><span class="mb-1 block text-sm font-medium">Số lượng trả</span><input type="number" name="items[{{ $loop->index }}][quantity]" value="{{ $selectedRow['quantity'] ?? '1' }}" min="1" max="{{ $item['returnable_quantity'] }}" step="1" :disabled="!selected" required class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
                        <input type="hidden" name="items[{{ $loop->index }}][item_id]" value="{{ $item['item_id'] }}" :disabled="!selected"><input type="hidden" name="items[{{ $loop->index }}][restock_quantity]" value="0" :disabled="!selected">
                    </div>
                @endforeach
            </fieldset>
            <label class="block"><span class="mb-1 block text-sm font-medium">Lý do *</span><textarea name="reason" required maxlength="1000" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">{{ old('reason') }}</textarea></label>
            <label class="block"><span class="mb-1 block text-sm font-medium">Ghi chú</span><textarea name="note" maxlength="2000" rows="2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">{{ old('note') }}</textarea></label>
            <button class="min-h-11 rounded-xl bg-junie-navy px-5 text-sm font-semibold text-white" :disabled="submitting" x-text="submitting ? 'Đang lưu...' : 'Ghi nhận phiếu trả'"></button>
        </form>
    @endif
</div>
@endsection
