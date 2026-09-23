<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicAppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Appointment $appointment */
        $appointment = $this->resource;

        return [
            'booking_code' => $appointment->booking_code,
            'customer_name' => $appointment->isGuest()
                ? $appointment->guest_name
                : $appointment->user?->name,
            'doctor' => ['name' => $appointment->doctor->name],
            'service' => [
                'name' => $appointment->service->name,
                'duration' => $appointment->service->duration,
            ],
            'appointment_date' => $appointment->appointment_date->toDateString(),
            'start_time' => substr($appointment->start_time, 0, 5),
            'end_time' => substr($appointment->end_time, 0, 5),
            'status' => $appointment->status,
            'can_reschedule' => $appointment->canBeRescheduled(),
            'reschedule_block_reason' => $appointment->rescheduleBlockReason(),
            'can_cancel' => $appointment->canBeCancelledByCustomer(),
            'cancel_block_reason' => $appointment->cancellationBlockReason(),
            'minimum_change_notice_hours' => max(0, (int) config('booking.customer_changes.minimum_notice_hours', 2)),
            'reschedules_remaining' => max(0, Appointment::RESCHEDULE_LIMIT - ($appointment->reschedule_count ?? 0)),
            'was_rescheduled' => $appointment->rescheduled_at !== null || ($appointment->reschedule_count ?? 0) > 0,
        ];
    }
}
