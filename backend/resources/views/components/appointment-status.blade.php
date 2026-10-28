@props(['status'])

@php
    $states = [
        \App\Models\Appointment::STATUS_PENDING => ['Chờ xác nhận', 'gold'],
        \App\Models\Appointment::STATUS_CONFIRMED => ['Đã xác nhận', 'success'],
        \App\Models\Appointment::STATUS_CHECKED_IN => ['Đã check-in', 'navy'],
        \App\Models\Appointment::STATUS_IN_PROGRESS => ['Đang khám', 'navy'],
        \App\Models\Appointment::STATUS_TREATMENT_DONE => ['Bác sĩ đã hoàn thành', 'success'],
        \App\Models\Appointment::STATUS_COMPLETED => ['Hoàn thành', 'success'],
        \App\Models\Appointment::STATUS_CANCELLED => ['Đã hủy', 'danger'],
        \App\Models\Appointment::STATUS_NO_SHOW => ['Không đến', 'danger'],
    ];
    [$label, $tone] = $states[$status] ?? [$status, 'navy'];
@endphp

<x-badge :tone="$tone">{{ $label }}</x-badge>
