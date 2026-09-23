<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
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
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor_name,
            'actor_role' => $this->actor_role,
            'action' => $this->action,
            'module' => $this->module,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'target_name' => $this->target_name,
            'description' => $this->description,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'metadata' => $this->metadata,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'request_method' => $this->request_method,
            'request_url' => $this->request_url,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
