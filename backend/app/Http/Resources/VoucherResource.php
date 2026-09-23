<?php

namespace App\Http\Resources;

use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoucherResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status === Voucher::STATUS_ACTIVE && $this->isExpired()
            ? Voucher::STATUS_EXPIRED
            : $this->status;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'source' => $this->source,
            'source_id' => $this->source_id,
            'milestone' => $this->source === Voucher::SOURCE_LOYALTY_MILESTONE
                ? (int) $this->source_id
                : null,
            'status' => $status,
            'expires_at' => $this->expires_at->toISOString(),
            'used_at' => $this->used_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'customer' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'used_appointment_id' => $this->whenLoaded('usedAppointment', fn (): ?int => $this->usedAppointment?->id),
        ];
    }
}
