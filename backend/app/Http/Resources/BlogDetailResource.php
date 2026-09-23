<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class BlogDetailResource extends BlogResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'content' => $this->content,
        ];
    }
}
