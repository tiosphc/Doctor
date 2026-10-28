@props(['variant' => 'primary', 'type' => 'button'])

@php
    $styles = [
        'primary' => 'bg-junie-navy text-white hover:bg-slate-800 focus-visible:outline-junie-navy',
        'secondary' => 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50 focus-visible:outline-slate-600',
        'danger' => 'bg-red-700 text-white hover:bg-red-800 focus-visible:outline-red-700',
    ];
@endphp

<button type="{{ $type }}" {{ $attributes->class(['inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50', $styles[$variant] ?? $styles['primary']]) }}>
    {{ $slot }}
</button>
