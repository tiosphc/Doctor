@extends('layouts.admin')

@section('title', 'Quản trị Blade')
@section('page-title', 'Nền tảng quản trị Blade')

@section('content')
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <x-badge>Phase 1</x-badge>
            <h1 class="mt-4 text-2xl font-semibold text-junie-navy">Giao diện quản trị Blade đã sẵn sàng</h1>
            <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-600">Layout, session đăng nhập và các thành phần dùng chung đã được kết nối với Laravel. Dashboard và từng module nghiệp vụ sẽ được chuyển theo các phase tiếp theo.</p>
            <a href="{{ rtrim((string) config('app.frontend_url'), '/') }}/admin" class="mt-6 inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Tiếp tục dùng quản trị hiện tại</a>
        </section>
        <x-empty-state title="Module tiếp theo" description="Admin Dashboard là module đầu tiên sẽ được chuyển sang Blade trong Phase 2." />
    </div>
@endsection
