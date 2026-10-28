@props(['value' => null, 'currency' => false])

@php
    $raw = is_numeric($value) ? (string) $value : '0';
    [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);
    $significantFraction = rtrim($fraction, '0');
    $formatted = $grouped.($significantFraction !== '' ? ','.$significantFraction : '').($currency ? ' ₫' : '');
@endphp

{{ $formatted }}
