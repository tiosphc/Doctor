@extends('layouts.admin')
@section('title', 'Sửa '.$order->code)
@section('page-title', 'Sửa phiếu mua')
@section('content')
<div class="space-y-5"><a href="{{ route('admin.web.purchase-orders.show', $order) }}" class="text-sm font-semibold text-junie-navy">← {{ $order->code }}</a><form method="POST" action="{{ route('admin.web.purchase-orders.update', $order) }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">@csrf @method('PATCH')<h1 class="text-2xl font-semibold">Sửa phiếu mua nháp {{ $order->code }}</h1>@error('operation')<p class="text-sm text-red-700">{{ $message }}</p>@enderror @include('admin.purchase-orders.form')<div class="flex justify-end"><x-button type="submit">Lưu thay đổi</x-button></div></form></div>
@endsection
