<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
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
            'rating' => $this->rating,
            'comment' => $this->comment,
            'status' => $this->when($request->user() !== null, $this->status),
            'verified' => true,
            'reviewer' => ['name' => $this->user?->name],
            'appointment_id' => $this->when($request->user() !== null, $this->appointment_id),
            'doctor' => $this->whenLoaded('doctor', fn (): array => ['id' => $this->doctor->id, 'name' => $this->doctor->name]),
            'service' => $this->whenLoaded('service', fn (): array => ['id' => $this->service->id, 'name' => $this->service->name]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
