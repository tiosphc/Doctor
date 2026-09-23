<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
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
            'customer_code' => $this->customer_code,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'email' => $this->primary_email,
            'phone' => $this->primary_phone,
            'role' => $this->user?->role ?? 'customer',
            'status' => $this->status,
            'completed_visits' => (int) $this->completed_visits,
            'last_appointment_date' => $this->last_appointment_date,
        ];
    }
}
