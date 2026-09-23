<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AdminServiceCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'hero_image' => $this->heroImageUrl(),
            'services_count' => $this->services_count,
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
