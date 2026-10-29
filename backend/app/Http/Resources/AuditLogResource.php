<?php

namespace App\Http\Resources;

use App\Services\AuditLogger;
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
        $sanitizer = app(AuditLogger::class);

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
            'old_values' => $sanitizer->sanitize($this->old_values ?? []),
            'new_values' => $sanitizer->sanitize($this->new_values ?? []),
            'metadata' => $sanitizer->sanitize($this->metadata ?? []),
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'request_method' => $this->request_method,
            'request_url' => $this->safeRequestUrl($sanitizer),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function safeRequestUrl(AuditLogger $sanitizer): ?string
    {
        if ($this->request_url === null) {
            return null;
        }

        [$base, $query] = array_pad(explode('?', $this->request_url, 2), 2, '');
        if ($query === '') {
            return $base;
        }

        parse_str($query, $parameters);
        $safeParameters = $sanitizer->sanitize($parameters);

        return $safeParameters === [] ? $base : $base.'?'.http_build_query($safeParameters);
    }
}
