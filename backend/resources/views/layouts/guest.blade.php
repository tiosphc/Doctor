@extends('layouts.app')

@section('body')
    <main class="flex min-h-screen items-center justify-center px-4 py-12 sm:px-6">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-junie-gold">Junie</p>
                <h1 class="mt-3 text-3xl font-semibold text-junie-navy">@yield('heading')</h1>
                <p class="mt-2 text-sm text-slate-600">@yield('description')</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <x-alerts />
                @yield('content')
            </div>
        </div>
    </main>
@endsection
