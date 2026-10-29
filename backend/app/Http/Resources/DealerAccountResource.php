<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealerAccountResource extends JsonResource
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
            'code' => $this->code,
            'legal_name' => $this->legal_name,
            'trading_name' => $this->trading_name,
            'contact_name' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'tax_code' => $this->tax_code,
            'billing_address_line1' => $this->billing_address_line1,
            'billing_address_line2' => $this->billing_address_line2,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'status' => $this->status,
            'source_application_id' => $this->source_application_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'membership_role' => $this->whenHas('membership_role'),
            'owner' => $this->whenLoaded('memberships', function (): ?array {
                $owner = $this->memberships->firstWhere('membership_role', 'owner');

                return $owner?->user ? ['id' => $owner->user->id, 'name' => $owner->user->name, 'email' => $owner->user->email] : null;
            }),
            'tier' => $this->whenLoaded('currentTier', fn (): ?array => $this->currentTier?->only(['id', 'code', 'name'])),
            'wallet_balance' => $this->whenLoaded('wallet', fn (): string => (string) ($this->wallet?->balance ?? '0.00')),
            'memberships' => $this->whenLoaded('memberships', fn (): array => $this->memberships->map(fn ($member): array => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'user_name' => $member->user?->name,
                'membership_role' => $member->membership_role,
                'status' => $member->status,
            ])->all()),
        ];
    }
}
