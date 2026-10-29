<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealerTierHistoryResource extends JsonResource
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
            'dealer_account_id' => $this->dealer_account_id,
            'previous_tier' => $this->previousTier ? ['id' => $this->previousTier->id, 'code' => $this->previousTier->code, 'name' => $this->previousTier->name] : null,
            'new_tier' => ['id' => $this->newTier->id, 'code' => $this->newTier->code, 'name' => $this->newTier->name],
            'source' => $this->source,
            'reason' => $this->reason,
            'net_revenue_snapshot' => $this->net_revenue_snapshot,
            'policy_version' => $this->policy_version,
            'evaluation_period' => $this->evaluation_period,
            'revenue_period_start' => $this->revenue_period_start?->toDateString(),
            'revenue_period_end' => $this->revenue_period_end?->toDateString(),
            'evaluated_at' => $this->evaluated_at?->toIso8601String(),
            'operation_key' => $this->operation_key,
            'effective_at' => $this->effective_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name] : null,
        ];
    }
}
