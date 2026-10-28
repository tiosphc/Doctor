@extends('layouts.admin')

@section('title', 'Thêm danh mục')
@section('page-title', 'Thêm danh mục')

@section('content')
<div class="mx-auto max-w-3xl space-y-5"><a href="{{ route('admin.web.categories.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Danh mục</a><div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h1 class="mb-5 text-2xl font-semibold text-junie-navy">Thêm danh mục</h1><form method="POST" action="{{ route('admin.web.categories.store') }}">@csrf @include('admin.categories.form', ['category' => null])<div class="mt-6 flex flex-wrap justify-end gap-3"><a href="{{ route('admin.web.categories.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold">Hủy</a><x-button type="submit">Lưu danh mục</x-button></div></form></div></div>
@endsection
