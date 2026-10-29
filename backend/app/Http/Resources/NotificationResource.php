<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'event' => $data['event'] ?? null,
            'title' => $data['title'] ?? null,
            'message' => $data['message'] ?? null,
            'appointment_id' => $data['appointment_id'] ?? null,
            'booking_code' => $data['booking_code'] ?? null,
            'action_url' => $data['action_url'] ?? null,
            'status' => $data['status'] ?? null,
            'source' => $data['source'] ?? null,
            'actor_role' => $data['actor_role'] ?? null,
            'audience' => $data['audience'] ?? null,
            'data' => $data['data'] ?? null,
            'created_at' => $this->created_at?->toISOString(),
            'read_at' => $this->read_at?->toISOString(),
        ];
    }
}
