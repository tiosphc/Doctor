@extends('layouts.admin')

@section('title', 'Sửa '.$unit->name)
@section('page-title', 'Sửa đơn vị')

@section('content')
<div class="mx-auto max-w-3xl space-y-5"><a href="{{ route('admin.web.units.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Đơn vị</a><div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="mb-5 flex flex-wrap items-center justify-between gap-3"><h1 class="text-2xl font-semibold text-junie-navy">{{ $unit->name }}</h1><x-product-status :status="$unit->status" /></div><form method="POST" action="{{ route('admin.web.units.update', $unit) }}">@csrf @method('PATCH') @include('admin.units.form')<div class="mt-6 flex flex-wrap justify-end gap-3"><a href="{{ route('admin.web.units.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold">Hủy</a><x-button type="submit">Lưu thay đổi</x-button></div></form></div><p class="text-sm text-slate-500">Ngừng hoạt động trong danh sách đơn vị. Các SKU cũ vẫn giữ đơn vị này.</p></div>
@endsection
