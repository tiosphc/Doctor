<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminBlogDetailResource extends AdminBlogResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'content' => $this->content,
        ];
    }
}
