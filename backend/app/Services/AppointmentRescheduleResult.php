<?php

namespace App\Services;

use App\Models\Appointment;

final class AppointmentRescheduleResult
{
    /**
     * @param  array{appointment_date: string, start_time: string, end_time: string}  $previousSchedule
     */
    public function __construct(
        public readonly Appointment $appointment,
        public readonly array $previousSchedule,
    ) {}
}
