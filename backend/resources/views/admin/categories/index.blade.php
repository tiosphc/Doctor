@extends('layouts.admin')

@section('title', 'Danh mục sản phẩm')
@section('page-title', 'Danh mục sản phẩm')

@section('content')
<div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Product Master</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">Danh mục</h1><p class="mt-2 text-sm text-slate-600">Quản lý danh mục cha/con và trạng thái dùng trong Product.</p></div>
        <a href="{{ route('admin.web.categories.create') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white hover:bg-slate-800">Thêm danh mục</a>
    </header>
    <form method="GET" action="{{ route('admin.web.categories.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[minmax(0,1fr)_12rem_auto]">
        <div><label for="category-search" class="block text-sm font-semibold text-slate-700">Tìm theo tên hoặc mã</label><input id="category-search" name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"></div>
        <div><label for="category-status" class="block text-sm font-semibold text-slate-700">Trạng thái</label><select id="category-status" name="status" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Tất cả</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Hoạt động</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Ngừng hoạt động</option></select></div>
        <div class="flex flex-wrap items-end gap-2"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.categories.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-3 text-sm font-semibold">Xóa lọc</a></div>
    </form>
    <p class="text-sm text-slate-500">{{ $categories->total() }} danh mục · Trang {{ $categories->currentPage() }}/{{ $categories->lastPage() }}</p>
    @if($categories->isEmpty())
        <x-empty-state title="Chưa có danh mục phù hợp." />
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Tên / Mã</th><th class="px-4 py-3">Danh mục cha</th><th class="px-4 py-3">Thứ tự</th><th class="px-4 py-3">Sản phẩm</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Thao tác</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($categories as $category)
                        <tr>
                            <td class="px-4 py-3"><p class="font-semibold text-junie-navy">{{ $category->name }}</p><p class="break-all font-mono text-xs text-slate-500">{{ $category->code }}</p></td>
                            <td class="px-4 py-3">{{ $category->parent?->name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $category->sort_order }}</td>
                            <td class="px-4 py-3">{{ $category->products_count }}</td>
                            <td class="px-4 py-3"><x-product-status :status="$category->status" /></td>
                            <td class="px-4 py-3"><div class="flex flex-wrap items-center gap-3"><a href="{{ route('admin.web.categories.edit', $category) }}" class="font-semibold text-junie-navy hover:underline">Sửa</a>@if($category->status === 'active')<x-confirm-modal :action="route('admin.web.categories.update', $category)" method="PATCH" title="Ngừng hoạt động danh mục" :message="'Ngừng hoạt động '.$category->name.'? Sản phẩm đang sử dụng vẫn giữ liên kết.'" confirm-label="Ngừng hoạt động" :fields="['status' => 'inactive']" class="font-semibold text-red-700"><x-slot:trigger>Ngừng hoạt động</x-slot:trigger></x-confirm-modal>@else<form method="POST" action="{{ route('admin.web.categories.update', $category) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="active"><button type="submit" class="font-semibold text-junie-navy hover:underline">Kích hoạt</button></form>@endif</div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-pagination :paginator="$categories" />
    @endif
</div>
@endsection
