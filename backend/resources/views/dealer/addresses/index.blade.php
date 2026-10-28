@extends('layouts.dealer')

@section('title', 'Địa chỉ giao hàng')
@section('page-title', 'Địa chỉ giao hàng')

@section('content')
<div class="space-y-6"><header class="flex flex-wrap items-end justify-between gap-4"><div><h1 class="text-3xl font-semibold text-junie-navy">Địa chỉ giao hàng</h1><p class="mt-2 text-sm text-slate-600">Địa chỉ của {{ $account->legal_name }}. Đơn đã tạo vẫn giữ thông tin giao hàng tại thời điểm đặt.</p></div><a href="{{ route('dealer.web.addresses.create') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Thêm địa chỉ</a></header>
    @if ($addresses->isEmpty())
        <x-empty-state title="Chưa có địa chỉ giao hàng." />
    @else
        <div class="grid gap-4 md:grid-cols-2">@foreach ($addresses as $address)<article class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex flex-wrap items-start justify-between gap-2"><h2 class="break-words font-semibold text-junie-navy">{{ $address->recipient_name }}</h2>@if ($address->is_default)<x-badge tone="success">Mặc định</x-badge>@endif</div><p class="mt-2 text-sm">{{ $address->recipient_phone }}</p><p class="mt-2 break-words text-sm text-slate-600">{{ $address->address_line }}, {{ $address->ward?->name }}, {{ $address->province?->name }}@if ($address->postal_code) · {{ $address->postal_code }}@endif</p><div class="mt-4 flex flex-wrap items-center gap-4"><a href="{{ route('dealer.web.addresses.edit', $address->id) }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-junie-navy underline">Sửa</a><x-confirm-modal :action="route('dealer.web.addresses.destroy', $address->id)" method="DELETE" title="Xóa địa chỉ giao hàng" :message="'Xóa địa chỉ của '.$address->recipient_name.'? Đơn đã tạo không thay đổi.'" confirm-label="Xóa địa chỉ" class="text-sm font-semibold text-red-700"><x-slot:trigger>Xóa</x-slot:trigger></x-confirm-modal></div></article>@endforeach</div>
    @endif
    <a href="{{ route('dealer.web.quick.create') }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-junie-navy underline">Đến Đặt hàng nhanh →</a>
</div>
@endsection
