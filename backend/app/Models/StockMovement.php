<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockMovement extends Model
{
    protected $guarded = ['*'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'before_on_hand_quantity' => 'decimal:3', 'after_on_hand_quantity' => 'decimal:3', 'unit_cost' => 'decimal:6', 'cost_amount' => 'decimal:2', 'occurred_at' => 'datetime'];
    }

    public function save(array $options = []): bool
    {
        throw new LogicException('Stock movements are immutable.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Stock movements are immutable.');
    }
}
