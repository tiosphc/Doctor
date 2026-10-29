<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminStaffResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'doctor' => $this->whenLoaded('doctorProfile', fn (): ?DoctorResource => $this->doctorProfile ? new DoctorResource($this->doctorProfile) : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
