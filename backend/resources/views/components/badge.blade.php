@props(['tone' => 'navy'])

@php
    $styles = [
        'navy' => 'border-blue-200 bg-blue-50 text-junie-navy',
        'gold' => 'border-amber-200 bg-amber-50 text-amber-900',
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'danger' => 'border-red-200 bg-red-50 text-red-800',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold', $styles[$tone] ?? $styles['navy']]) }}>
    {{ $slot }}
</span>
