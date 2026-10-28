@extends('layouts.admin')

@section('title', 'Thương hiệu')
@section('page-title', 'Thương hiệu')

@section('content')
<div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Product Master</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">Thương hiệu</h1><p class="mt-2 text-sm text-slate-600">Quản lý thương hiệu dùng trong Product.</p></div><a href="{{ route('admin.web.brands.create') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white hover:bg-slate-800">Thêm thương hiệu</a></header>
    <form method="GET" action="{{ route('admin.web.brands.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[minmax(0,1fr)_12rem_auto]">
        <div><label for="brand-search" class="block text-sm font-semibold text-slate-700">Tìm theo tên hoặc mã</label><input id="brand-search" name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"></div>
        <div><label for="brand-status" class="block text-sm font-semibold text-slate-700">Trạng thái</label><select id="brand-status" name="status" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Tất cả</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Hoạt động</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Ngừng hoạt động</option></select></div>
        <div class="flex flex-wrap items-end gap-2"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.brands.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-3 text-sm font-semibold">Xóa lọc</a></div>
    </form>
    <p class="text-sm text-slate-500">{{ $brands->total() }} thương hiệu · Trang {{ $brands->currentPage() }}/{{ $brands->lastPage() }}</p>
    @if($brands->isEmpty())
        <x-empty-state title="Chưa có thương hiệu phù hợp." />
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="w-full min-w-[590px] text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Tên / Mã</th><th class="px-4 py-3">Sản phẩm</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Thao tác</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach($brands as $brand)<tr><td class="px-4 py-3"><p class="font-semibold text-junie-navy">{{ $brand->name }}</p><p class="break-all font-mono text-xs text-slate-500">{{ $brand->code }}</p></td><td class="px-4 py-3">{{ $brand->products_count }}</td><td class="px-4 py-3"><x-product-status :status="$brand->status" /></td><td class="px-4 py-3"><div class="flex flex-wrap items-center gap-3"><a href="{{ route('admin.web.brands.edit', $brand) }}" class="font-semibold text-junie-navy hover:underline">Sửa</a>@if($brand->status === 'active')<x-confirm-modal :action="route('admin.web.brands.update', $brand)" method="PATCH" title="Ngừng hoạt động thương hiệu" :message="'Ngừng hoạt động '.$brand->name.'? Sản phẩm đang sử dụng vẫn giữ liên kết.'" confirm-label="Ngừng hoạt động" :fields="['status' => 'inactive']" class="font-semibold text-red-700"><x-slot:trigger>Ngừng hoạt động</x-slot:trigger></x-confirm-modal>@else<form method="POST" action="{{ route('admin.web.brands.update', $brand) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="active"><button type="submit" class="font-semibold text-junie-navy hover:underline">Kích hoạt</button></form>@endif</div></td></tr>@endforeach</tbody></table></div>
        <x-pagination :paginator="$brands" />
    @endif
</div>
@endsection
