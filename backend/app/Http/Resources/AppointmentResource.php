<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_type' => $this->isGuest() ? 'guest' : 'registered',
            'customer' => $this->isGuest() ? null : new UserResource($this->whenLoaded('user')),
            'guest' => $this->when($this->isGuest(), function () use ($request): array {
                $guest = ['name' => $this->guest_name];

                if ($request->is('api/admin/*') || $request->is('api/receptionist/*')) {
                    $guest['email'] = $this->guest_email;
                    $guest['phone'] = $this->guest_phone;
                }

                return $guest;
            }),
            'booking_code' => $this->booking_code,
            'doctor' => new DoctorResource($this->whenLoaded('doctor')),
            'service' => new ServiceResource($this->whenLoaded('service')),
            'review' => new ReviewResource($this->whenLoaded('review')),
            'voucher' => new VoucherResource($this->whenLoaded('voucher')),
            'pricing' => [
                'original_price' => $this->original_price,
                'discount_amount' => $this->discount_amount,
                'final_price' => $this->final_price,
            ],
            'appointment_date' => $this->appointment_date->toDateString(),
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'status' => $this->status,
            'can_reschedule' => $this->canBeRescheduled(),
            'reschedule_block_reason' => $this->rescheduleBlockReason(),
            'can_cancel' => $this->canBeCancelledByCustomer(),
            'cancel_block_reason' => $this->cancellationBlockReason(),
            'minimum_change_notice_hours' => max(0, (int) config('booking.customer_changes.minimum_notice_hours', 2)),
            'reschedule_limit' => Appointment::RESCHEDULE_LIMIT,
            'reschedules_remaining' => max(0, Appointment::RESCHEDULE_LIMIT - ($this->reschedule_count ?? 0)),
            'has_exhausted_reschedules' => ($this->reschedule_count ?? 0) >= Appointment::RESCHEDULE_LIMIT,
            'was_rescheduled' => $this->rescheduled_at !== null || ($this->reschedule_count ?? 0) > 0,
            'rescheduled_at' => $this->rescheduled_at?->toISOString(),
            'reschedule_count' => $this->reschedule_count,
            'original_appointment_date' => $this->original_appointment_date?->toDateString(),
            'original_start_time' => $this->original_start_time ? substr($this->original_start_time, 0, 5) : null,
            'original_end_time' => $this->original_end_time ? substr($this->original_end_time, 0, 5) : null,
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
