@extends('layouts.admin')

@section('title', 'Thêm vùng phục vụ kho')
@section('page-title', 'Thêm vùng phục vụ kho')

@section('content')
<div class="mx-auto max-w-2xl space-y-6">
    <header><h1 class="text-3xl font-semibold text-junie-navy">Thêm vùng phục vụ kho</h1><p class="mt-2 text-sm text-slate-600">Gán một kho cho một tỉnh / thành. Mỗi cặp kho và tỉnh chỉ được tạo một lần.</p></header>
    <form method="POST" action="{{ route('admin.web.warehouse-service-areas.store') }}" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        <label class="block"><span class="text-sm font-semibold">Kho</span><select name="warehouse_id" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option value="">Chọn kho</option>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }} ({{ $warehouse->code }}){{ $warehouse->status !== 'active' ? ' · Ngừng hoạt động' : '' }}</option>@endforeach</select>@error('warehouse_id')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror</label>
        <label class="block"><span class="text-sm font-semibold">Tỉnh / thành</span><select name="province_code" required class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option value="">Chọn tỉnh / thành</option>@foreach ($provinces as $province)<option value="{{ $province->code }}" @selected(old('province_code') === $province->code)>{{ $province->name }} ({{ $province->code }})</option>@endforeach</select>@error('province_code')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror</label>
        <label class="block"><span class="text-sm font-semibold">Độ ưu tiên</span><input type="number" name="priority" min="0" max="65535" step="1" required value="{{ old('priority', 1) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3"><span class="mt-1 block text-xs text-slate-500">Số nhỏ hơn được ưu tiên trước. Kho ngừng hoạt động không được chọn cho đơn mới.</span>@error('priority')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror</label>
        <div class="flex flex-wrap gap-3"><button class="min-h-11 rounded-xl bg-junie-navy px-5 text-sm font-semibold text-white">Lưu vùng phục vụ</button><a href="{{ route('admin.web.warehouse-service-areas.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold">Hủy</a></div>
    </form>
</div>
@endsection
