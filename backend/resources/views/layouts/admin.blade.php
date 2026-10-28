@extends('layouts.app')

@section('body')
    <div class="min-h-screen lg:flex" x-data="{ sidebarOpen: false, sidebarCollapsed: false }" @keydown.escape.window="sidebarOpen = false">
        <div x-cloak x-show="sidebarOpen" class="fixed inset-0 z-40 bg-slate-950/50 lg:hidden" @click="sidebarOpen = false"></div>
        <x-admin-sidebar />
        <div class="min-w-0 flex-1 transition-[padding] duration-200" :class="sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-64'">
            <x-topbar :title="trim($__env->yieldContent('page-title', 'Quản trị'))" />
            <main class="mx-auto max-w-7xl px-4 py-7 sm:px-6 lg:px-8">
                <x-alerts />
                @yield('content')
            </main>
        </div>
    </div>
@endsection
