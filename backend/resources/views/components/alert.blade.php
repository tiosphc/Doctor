@props(['tone' => 'info'])

@php
    $styles = [
        'info' => 'border-blue-200 bg-blue-50 text-blue-900',
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
        'error' => 'border-red-200 bg-red-50 text-red-900',
    ];
@endphp

<div role="alert" {{ $attributes->class(['rounded-xl border px-4 py-3 text-sm', $styles[$tone] ?? $styles['info']]) }}>
    {{ $slot }}
</div>
