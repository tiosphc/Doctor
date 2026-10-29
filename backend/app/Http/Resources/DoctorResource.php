<?php

namespace App\Http\Resources;

use App\Support\PublicImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'specialty' => $this->specialty,
            'bio' => $this->bio,
            'avatar' => PublicImage::url($this->avatar),
            'average_rating' => $this->when($this->hasReviewSummary(), fn (): float => $this->displayAverageRating()),
            'review_count' => $this->when($this->hasReviewSummary(), fn (): int => $this->displayReviewCount()),
            'rating_distribution' => $this->resource->getAttribute('rating_distribution'),
            'services' => ServiceResource::collection($this->whenLoaded('services')),
        ];
    }

    private function hasReviewSummary(): bool
    {
        return array_key_exists('published_reviews_count', $this->resource->getAttributes())
            && array_key_exists('published_reviews_sum_rating', $this->resource->getAttributes());
    }

    private function displayReviewCount(): int
    {
        return $this->baseline_review_count + (int) ($this->published_reviews_count ?? 0);
    }

    private function displayAverageRating(): float
    {
        $ratingTotal = ($this->baseline_review_count * 5)
            + (int) ($this->published_reviews_sum_rating ?? 0);

        return round($ratingTotal / $this->displayReviewCount(), 1);
    }
}
