@props(['title'])

<header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="flex min-h-20 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <div class="flex min-w-0 items-center gap-3">
            <button type="button" class="rounded-lg border border-slate-200 p-2 text-junie-navy lg:hidden" aria-label="Mở điều hướng" @click="sidebarOpen = true">☰</button>
            <div class="min-w-0">
                <p class="truncate text-lg font-semibold text-junie-navy">{{ $title }}</p>
                <p class="truncate text-xs text-slate-500">{{ auth()->user()->name }}</p>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            {{ $slot }}
            <form method="POST" action="{{ route('blade.logout') }}">
                @csrf
                <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-junie-navy">Đăng xuất</button>
            </form>
        </div>
    </div>
</header>
