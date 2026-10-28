@extends('layouts.admin')

@section('title', 'Thêm thương hiệu')
@section('page-title', 'Thêm thương hiệu')

@section('content')
<div class="mx-auto max-w-3xl space-y-5"><a href="{{ route('admin.web.brands.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Thương hiệu</a><div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h1 class="mb-5 text-2xl font-semibold text-junie-navy">Thêm thương hiệu</h1><form method="POST" action="{{ route('admin.web.brands.store') }}">@csrf @include('admin.brands.form', ['brand' => null])<div class="mt-6 flex flex-wrap justify-end gap-3"><a href="{{ route('admin.web.brands.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold">Hủy</a><x-button type="submit">Lưu thương hiệu</x-button></div></form></div></div>
@endsection
