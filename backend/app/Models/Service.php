<?php

namespace App\Models;

use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'category_id',
    'name',
    'slug',
    'description',
    'short_description',
    'introduction',
    'duration',
    'duration_note',
    'price',
    'price_note',
    'image',
    'hero_image',
    'hero_disclaimer',
    'cta_label',
    'status',
    'sort_order',
    'seo_title',
    'seo_description',
    'content',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function publishedReviews(): HasMany
    {
        return $this->reviews()->where('status', Review::STATUS_PUBLISHED);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'price' => 'decimal:2',
            'sort_order' => 'integer',
            'content' => 'array',
        ];
    }
}
