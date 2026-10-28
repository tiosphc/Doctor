@extends('layouts.admin')

@section('title', 'Đơn vị')
@section('page-title', 'Đơn vị')

@section('content')
<div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Product Master</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">Đơn vị</h1><p class="mt-2 text-sm text-slate-600">Đơn vị được lưu trên từng SKU. Ngừng hoạt động không xóa liên kết đang dùng.</p></div><a href="{{ route('admin.web.units.create') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white hover:bg-slate-800">Thêm đơn vị</a></header>
    <form method="GET" action="{{ route('admin.web.units.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[minmax(0,1fr)_12rem_auto]">
        <div><label for="unit-search" class="block text-sm font-semibold text-slate-700">Tìm theo tên hoặc mã</label><input id="unit-search" name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"></div>
        <div><label for="unit-status" class="block text-sm font-semibold text-slate-700">Trạng thái</label><select id="unit-status" name="status" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Tất cả</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Hoạt động</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Ngừng hoạt động</option></select></div>
        <div class="flex flex-wrap items-end gap-2"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.units.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-3 text-sm font-semibold">Xóa lọc</a></div>
    </form>
    <p class="text-sm text-slate-500">{{ $units->total() }} đơn vị · Trang {{ $units->currentPage() }}/{{ $units->lastPage() }}</p>
    @if($units->isEmpty())
        <x-empty-state title="Chưa có đơn vị phù hợp." />
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="w-full min-w-[650px] text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Tên / Mã</th><th class="px-4 py-3">Ký hiệu</th><th class="px-4 py-3">Thập phân</th><th class="px-4 py-3">SKU</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Thao tác</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach($units as $unit)<tr><td class="px-4 py-3"><p class="font-semibold text-junie-navy">{{ $unit->name }}</p><p class="break-all font-mono text-xs text-slate-500">{{ $unit->code }}</p></td><td class="px-4 py-3">{{ $unit->symbol }}</td><td class="px-4 py-3">{{ $unit->decimal_precision }}</td><td class="px-4 py-3">{{ $unit->variants_count }}</td><td class="px-4 py-3"><x-product-status :status="$unit->status" /></td><td class="px-4 py-3"><div class="flex flex-wrap items-center gap-3"><a href="{{ route('admin.web.units.edit', $unit) }}" class="font-semibold text-junie-navy hover:underline">Sửa</a>@if($unit->status === 'active')<x-confirm-modal :action="route('admin.web.units.update', $unit)" method="PATCH" title="Ngừng hoạt động đơn vị" :message="'Ngừng hoạt động '.$unit->name.'? SKU đang sử dụng vẫn giữ liên kết.'" confirm-label="Ngừng hoạt động" :fields="['status' => 'inactive']" class="font-semibold text-red-700"><x-slot:trigger>Ngừng hoạt động</x-slot:trigger></x-confirm-modal>@else<form method="POST" action="{{ route('admin.web.units.update', $unit) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="active"><button type="submit" class="font-semibold text-junie-navy hover:underline">Kích hoạt</button></form>@endif</div></td></tr>@endforeach</tbody></table></div>
        <x-pagination :paginator="$units" />
    @endif
</div>
@endsection
