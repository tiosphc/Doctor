@extends('layouts.dealer')

@section('title', 'Đại lý Blade')
@section('page-title', 'Nền tảng đại lý Blade')

@section('content')
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <x-badge>Phase 1</x-badge>
        <h1 class="mt-4 text-2xl font-semibold text-junie-navy">Xin chào, {{ auth()->user()->name }}</h1>
        <p class="mt-2 text-sm text-slate-600">Tài khoản đại lý: {{ $account->trading_name ?: $account->legal_name }}</p>
        <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-600">Layout và quyền truy cập đại lý đã kết nối với tài khoản đang hoạt động. Đặt hàng, ví và các nghiệp vụ khác tiếp tục hoạt động trên giao diện hiện tại cho tới khi được chuyển và kiểm thử.</p>
        <a href="{{ rtrim((string) config('app.frontend_url'), '/') }}/dealer" class="mt-6 inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Tiếp tục dùng cổng đại lý hiện tại</a>
    </section>
@endsection
