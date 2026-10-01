<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\VoucherResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class CustomerDetailResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $loyalty
     */
    public function __construct($resource, private readonly array $loyalty, private readonly Collection $availableVouchers)
    {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_code' => $this->customer_code,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'email' => $this->primary_email,
            'phone' => $this->primary_phone,
            'role' => $this->user?->role ?? 'customer',
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'loyalty' => $this->loyalty,
            'statistics' => [
                'total_appointments' => (int) $this->appointments_count,
                'completed_appointments' => (int) $this->completed_appointments_count,
                'upcoming_appointments' => (int) $this->upcoming_appointments_count,
                'cancelled_appointments' => (int) $this->cancelled_appointments_count,
                'reviews' => (int) ($this->user?->reviews_count ?? 0),
                'available_vouchers' => (int) ($this->user?->available_vouchers_count ?? 0),
            ],
            'loyalty_vouchers' => VoucherResource::collection($this->user?->vouchers ?? collect()),
            'available_vouchers' => VoucherResource::collection($this->availableVouchers),
            'recent_appointments' => $this->appointments->map(fn ($appointment): array => [
                'id' => $appointment->id,
                'booking_code' => $appointment->booking_code,
                'service_name' => $appointment->service?->name,
                'doctor_name' => $appointment->doctor?->name,
                'appointment_date' => $appointment->appointment_date,
                'start_time' => $appointment->start_time,
                'status' => $appointment->status,
            ]),
        ];
    }
}
