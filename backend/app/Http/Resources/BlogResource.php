<?php

namespace App\Http\Resources;

use App\Support\PublicImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'category' => $this->category?->name,
            'excerpt' => $this->excerpt,
            'image' => PublicImage::url($this->image),
            'published_at' => $this->published_at,
            'author' => $this->whenLoaded('author', fn (): array => [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ]),
        ];
    }
}
