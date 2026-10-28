@extends('layouts.admin')
@section('title', 'Tạo phiếu mua')
@section('page-title', 'Tạo phiếu mua')
@section('content')
<div class="space-y-5"><a href="{{ route('admin.web.purchase-orders.index') }}" class="text-sm font-semibold text-junie-navy">← Phiếu mua</a><form method="POST" action="{{ route('admin.web.purchase-orders.store') }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">@csrf<h1 class="text-2xl font-semibold">Tạo phiếu mua nháp</h1>@error('operation')<p class="text-sm text-red-700">{{ $message }}</p>@enderror @include('admin.purchase-orders.form', ['order' => null])<div class="flex justify-end"><x-button type="submit">Lưu phiếu mua</x-button></div></form></div>
@endsection
