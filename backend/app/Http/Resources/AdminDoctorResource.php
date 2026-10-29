<?php

namespace App\Http\Resources;

use App\Models\Doctor;
use App\Models\User;
use App\Support\PublicImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminDoctorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->whenLoaded('user');
        $linkedUser = $user instanceof User ? $user : null;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $linkedUser?->name ?? $this->name,
            'specialty' => $this->specialty,
            'bio' => $this->bio,
            'phone' => $linkedUser?->phone ?? $this->phone,
            'email' => $linkedUser?->email ?? $this->email,
            'avatar' => PublicImage::url($this->avatar),
            'status' => $this->status,
            'account_status' => $this->accountStatus($linkedUser),
            'must_change_password' => $linkedUser?->must_change_password,
            'configuration_status' => $this->configurationStatus(),
            'is_booking_ready' => $this->configurationStatus() === 'ready',
            'services_count' => $this->relationshipCount('services'),
            'schedules_count' => $this->when(
                array_key_exists('schedules_count', $this->resource->getAttributes()),
                (int) ($this->schedules_count ?? 0),
            ),
            'services' => AdminServiceResource::collection($this->whenLoaded('services')),
        ];
    }

    private function configurationStatus(): string
    {
        if ($this->status === Doctor::STATUS_INACTIVE) {
            return 'inactive';
        }

        if ($this->relationshipCount('services') === 0) {
            return 'missing_services';
        }

        if ($this->relationshipCount('schedules') === 0) {
            return 'missing_schedule';
        }

        return 'ready';
    }

    private function relationshipCount(string $relationship): int
    {
        $countAttribute = $relationship.'_count';

        if (array_key_exists($countAttribute, $this->resource->getAttributes())) {
            return (int) ($this->resource->getAttribute($countAttribute) ?? 0);
        }

        if ($this->resource->relationLoaded($relationship)) {
            return $this->resource->getRelation($relationship)->count();
        }

        return 0;
    }

    private function accountStatus(?User $user): string
    {
        if ($user === null) {
            return 'legacy_unlinked';
        }

        if ($this->status === Doctor::STATUS_INACTIVE) {
            return 'suspended';
        }

        return $user->must_change_password ? 'pending_setup' : 'active';
    }
}
