@extends('layouts.admin')

@section('title', 'Nhà cung cấp')
@section('page-title', 'Nhà cung cấp')

@section('content')
<div class="space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-4">
        <div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Procurement</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">Nhà cung cấp</h1></div>
        <a href="{{ route('admin.web.suppliers.create') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Thêm nhà cung cấp</a>
    </header>
    <form method="GET" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_12rem_auto]">
        <label class="text-sm font-semibold">Tìm mã hoặc tên<input name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"></label>
        <label class="text-sm font-semibold">Trạng thái<select name="status" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Tất cả</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Hoạt động</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Ngừng hoạt động</option></select></label>
        <div class="flex items-end gap-2"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.suppliers.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-3 text-sm">Xóa lọc</a></div>
    </form>
    <p class="text-sm text-slate-500">{{ $suppliers->total() }} nhà cung cấp</p>
    @if($suppliers->isEmpty())<x-empty-state title="Chưa có nhà cung cấp phù hợp." />@else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table class="w-full min-w-[720px] text-left text-sm"><thead class="bg-slate-50"><tr><th class="px-4 py-3">Mã / Tên</th><th class="px-4 py-3">Liên hệ</th><th class="px-4 py-3">Đơn mua</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Thao tác</th></tr></thead><tbody class="divide-y">@foreach($suppliers as $supplier)<tr><td class="px-4 py-3"><p class="font-semibold">{{ $supplier->name }}</p><p class="font-mono text-xs text-slate-500">{{ $supplier->code }}</p></td><td class="px-4 py-3">{{ $supplier->contact_name ?: '—' }}<p class="text-xs text-slate-500">{{ $supplier->phone ?: $supplier->email }}</p></td><td class="px-4 py-3">{{ $supplier->purchase_orders_count }}</td><td class="px-4 py-3"><x-product-status :status="$supplier->status" /></td><td class="px-4 py-3"><a class="font-semibold text-junie-navy underline" href="{{ route('admin.web.suppliers.edit', $supplier) }}">Sửa</a></td></tr>@endforeach</tbody></table></div>
        <x-pagination :paginator="$suppliers" />
    @endif
</div>
@endsection
