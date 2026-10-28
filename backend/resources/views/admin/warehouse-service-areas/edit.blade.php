@extends('layouts.admin')

@section('title', 'Sửa độ ưu tiên vùng phục vụ')
@section('page-title', 'Sửa độ ưu tiên vùng phục vụ')

@section('content')
<div class="mx-auto max-w-2xl space-y-6">
    <header><h1 class="text-3xl font-semibold text-junie-navy">Sửa độ ưu tiên vùng phục vụ</h1><p class="mt-2 text-sm text-slate-600">{{ $serviceArea->warehouse?->name }} · {{ $serviceArea->province?->name ?? $serviceArea->province_code }}</p></header>
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">Thay đổi chỉ ảnh hưởng việc chọn kho cho đơn mới. Kho ngừng hoạt động không được chọn.</div>
    <form method="POST" action="{{ route('admin.web.warehouse-service-areas.update', $serviceArea) }}" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        @method('PATCH')
        <dl class="grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-slate-500">Kho</dt><dd class="font-semibold">{{ $serviceArea->warehouse?->name }} ({{ $serviceArea->warehouse?->code }})</dd></div><div><dt class="text-slate-500">Tỉnh / thành</dt><dd class="font-semibold">{{ $serviceArea->province?->name ?? $serviceArea->province_code }} ({{ $serviceArea->province_code }})</dd></div></dl>
        <label class="block"><span class="text-sm font-semibold">Độ ưu tiên</span><input type="number" name="priority" min="0" max="65535" step="1" required value="{{ old('priority', $serviceArea->priority) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3"><span class="mt-1 block text-xs text-slate-500">Số nhỏ hơn được xét trước; nếu bằng nhau, xét theo ID kho.</span>@error('priority')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror</label>
        <div class="flex flex-wrap gap-3"><button class="min-h-11 rounded-xl bg-junie-navy px-5 text-sm font-semibold text-white">Lưu độ ưu tiên</button><a href="{{ route('admin.web.warehouse-service-areas.index', ['warehouse_id' => $serviceArea->warehouse_id]) }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold">Quay lại</a></div>
    </form>
</div>
@endsection
