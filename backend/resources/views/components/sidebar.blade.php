@props(['title', 'items' => []])

<aside class="fixed inset-y-0 left-0 z-50 w-64 -translate-x-full bg-junie-navy text-white transition-transform lg:translate-x-0" :class="sidebarOpen && 'translate-x-0'" aria-label="Điều hướng chính">
    <div class="flex h-20 items-center justify-between border-b border-white/10 px-6">
        <span class="text-lg font-semibold tracking-wide">{{ $title }}</span>
        <button type="button" class="rounded-lg p-2 text-white lg:hidden" aria-label="Đóng điều hướng" @click="sidebarOpen = false">✕</button>
    </div>
    <nav class="flex flex-col gap-1 p-4">
        @foreach ($items as $item)
            <a href="{{ $item['href'] }}" @if ($item['active']) aria-current="page" @endif
                class="rounded-xl px-4 py-3 text-sm font-medium transition-colors {{ $item['active'] ? 'bg-white text-junie-navy' : 'text-slate-200 hover:bg-white/10 hover:text-white' }}">
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>
</aside>
