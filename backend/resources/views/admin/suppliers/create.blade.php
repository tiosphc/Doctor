@extends('layouts.admin')
@section('title', 'Thêm nhà cung cấp')
@section('page-title', 'Thêm nhà cung cấp')
@section('content')
<div class="mx-auto max-w-3xl space-y-5"><a href="{{ route('admin.web.suppliers.index') }}" class="text-sm font-semibold text-junie-navy">← Nhà cung cấp</a><form method="POST" action="{{ route('admin.web.suppliers.store') }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">@csrf<h1 class="mb-5 text-2xl font-semibold">Thêm nhà cung cấp</h1>@include('admin.suppliers.form', ['supplier' => null])<div class="mt-6 flex justify-end"><x-button type="submit">Lưu nhà cung cấp</x-button></div></form></div>
@endsection
