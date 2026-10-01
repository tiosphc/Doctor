<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealerApplicationResource extends JsonResource
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
            'company_name' => $this->company_name,
            'trading_name' => $this->trading_name,
            'contact_name' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'tax_code' => $this->tax_code,
            'business_address_line1' => $this->business_address_line1,
            'business_address_line2' => $this->business_address_line2,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'business_type' => $this->business_type,
            'estimated_monthly_purchase' => $this->estimated_monthly_purchase,
            'note' => $this->note,
            'status' => $this->status,
            'submitted_at' => $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'applicant' => $this->whenLoaded('user', fn (): array => ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email]),
            'reviewer' => $this->whenLoaded('reviewer', fn (): ?array => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null),
            'approved_account' => $this->whenLoaded('approvedAccount', fn (): ?array => $this->approvedAccount ? ['id' => $this->approvedAccount->id, 'code' => $this->approvedAccount->code] : null),
        ];
    }
}
