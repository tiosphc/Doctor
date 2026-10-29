<?php

namespace App\Models;

use Database\Factories\BlogCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug'])]
class BlogCategory extends Model
{
    /** @use HasFactory<BlogCategoryFactory> */
    use HasFactory;

    public function blogs(): HasMany
    {
        return $this->hasMany(Blog::class, 'category_id');
    }
}
