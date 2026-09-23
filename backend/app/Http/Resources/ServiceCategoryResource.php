<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ServiceCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'short_description' => $this->short_description,
            'hero_image' => $this->heroImageUrl(),
            'services_count' => $this->services_count,
            'services' => ServiceResource::collection($this->whenLoaded('services')),
        ];
    }

    private function heroImageUrl(): ?string
    {
        if ($this->hero_image === null) {
            return null;
        }

        if (filter_var($this->hero_image, FILTER_VALIDATE_URL) !== false || str_starts_with($this->hero_image, '//')) {
            return $this->hero_image;
        }

        $url = Storage::disk('public')->url($this->hero_image);

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : url($url);
    }
}
