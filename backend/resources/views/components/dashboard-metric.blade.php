@props(['label', 'detail' => null])

<div {{ $attributes->class(['min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm']) }}>
    <p class="text-sm text-slate-500">{{ $label }}</p>
    <p class="mt-2 break-words text-2xl font-semibold text-junie-navy">{{ $slot }}</p>
    @if ($detail)
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $detail }}</p>
    @endif
</div>
