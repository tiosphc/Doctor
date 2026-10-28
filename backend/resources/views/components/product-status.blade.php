@props(['status'])

@php
    [$label, $tone] = match ($status) {
        'active' => ['Active', 'success'],
        'inactive' => ['Inactive', 'danger'],
        'draft' => ['Draft', 'gold'],
        default => [$status, 'navy'],
    };
@endphp

<x-badge :tone="$tone">{{ $label }}</x-badge>
