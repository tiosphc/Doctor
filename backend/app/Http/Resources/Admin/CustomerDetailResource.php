<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\VoucherResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerDetailResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $loyalty
     */
    public function __construct($resource, private readonly array $loyalty)
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
        ];
    }
}
