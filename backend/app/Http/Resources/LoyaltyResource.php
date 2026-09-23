<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'completed_visits' => $this->resource['completed_visits'],
            'next_milestone' => $this->resource['next_milestone'],
            'achieved_milestones' => $this->resource['achieved_milestones'],
        ];
    }
}
