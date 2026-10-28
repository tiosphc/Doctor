@extends('layouts.admin')

@section('title', 'Thêm kho')
@section('page-title', 'Thêm kho')

@section('content')
<div class="mx-auto max-w-3xl space-y-5"><a href="{{ route('admin.web.warehouses.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Kho hàng</a><div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h1 class="mb-5 text-2xl font-semibold text-junie-navy">Thêm kho</h1><form method="POST" action="{{ route('admin.web.warehouses.store') }}">@csrf @include('admin.warehouses.form', ['warehouse' => null])<div class="mt-6 flex flex-wrap justify-end gap-3"><a href="{{ route('admin.web.warehouses.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold">Hủy</a><x-button type="submit">Lưu kho</x-button></div></form></div></div>
@endsection
