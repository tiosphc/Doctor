@extends('layouts.admin')

@section('title', 'Sửa '.$brand->name)
@section('page-title', 'Sửa thương hiệu')

@section('content')
<div class="mx-auto max-w-3xl space-y-5"><a href="{{ route('admin.web.brands.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Thương hiệu</a><div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="mb-5 flex flex-wrap items-center justify-between gap-3"><h1 class="text-2xl font-semibold text-junie-navy">{{ $brand->name }}</h1><x-product-status :status="$brand->status" /></div><form method="POST" action="{{ route('admin.web.brands.update', $brand) }}">@csrf @method('PATCH') @include('admin.brands.form')<div class="mt-6 flex flex-wrap justify-end gap-3"><a href="{{ route('admin.web.brands.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold">Hủy</a><x-button type="submit">Lưu thay đổi</x-button></div></form></div><p class="text-sm text-slate-500">Thay đổi trạng thái trong danh sách thương hiệu. Sản phẩm hiện có vẫn giữ liên kết khi thương hiệu ngừng hoạt động.</p></div>
@endsection
