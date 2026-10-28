@extends('layouts.admin')

@section('title', 'Sửa '.$category->name)
@section('page-title', 'Sửa danh mục')

@section('content')
<div class="mx-auto max-w-3xl space-y-5"><a href="{{ route('admin.web.categories.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Danh mục</a><div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="mb-5 flex flex-wrap items-center justify-between gap-3"><h1 class="text-2xl font-semibold text-junie-navy">{{ $category->name }}</h1><x-product-status :status="$category->status" /></div><form method="POST" action="{{ route('admin.web.categories.update', $category) }}">@csrf @method('PATCH') @include('admin.categories.form')<div class="mt-6 flex flex-wrap justify-end gap-3"><a href="{{ route('admin.web.categories.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold">Hủy</a><x-button type="submit">Lưu thay đổi</x-button></div></form></div><p class="text-sm text-slate-500">Thay đổi trạng thái trong danh sách danh mục. Sản phẩm hiện có vẫn giữ liên kết khi danh mục ngừng hoạt động.</p></div>
@endsection
