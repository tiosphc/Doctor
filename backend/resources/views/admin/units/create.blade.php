@extends('layouts.admin')

@section('title', 'Thêm đơn vị')
@section('page-title', 'Thêm đơn vị')

@section('content')
<div class="mx-auto max-w-3xl space-y-5"><a href="{{ route('admin.web.units.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Đơn vị</a><div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h1 class="mb-5 text-2xl font-semibold text-junie-navy">Thêm đơn vị</h1><form method="POST" action="{{ route('admin.web.units.store') }}">@csrf @include('admin.units.form', ['unit' => null])<div class="mt-6 flex flex-wrap justify-end gap-3"><a href="{{ route('admin.web.units.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold">Hủy</a><x-button type="submit">Lưu đơn vị</x-button></div></form></div></div>
@endsection
