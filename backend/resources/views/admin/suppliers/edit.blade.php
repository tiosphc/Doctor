@extends('layouts.admin')
@section('title', 'Sửa '.$supplier->name)
@section('page-title', 'Sửa nhà cung cấp')
@section('content')
<div class="mx-auto max-w-3xl space-y-5"><a href="{{ route('admin.web.suppliers.index') }}" class="text-sm font-semibold text-junie-navy">← Nhà cung cấp</a><form method="POST" action="{{ route('admin.web.suppliers.update', $supplier) }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">@csrf @method('PATCH')<h1 class="mb-5 text-2xl font-semibold">{{ $supplier->name }}</h1>@include('admin.suppliers.form')<p class="mt-4 text-xs text-slate-500">Mã không thể đổi sau khi nhà cung cấp đã có đơn mua. Ngừng hoạt động không xóa lịch sử.</p><div class="mt-6 flex justify-end"><x-button type="submit">Lưu thay đổi</x-button></div></form></div>
@endsection
