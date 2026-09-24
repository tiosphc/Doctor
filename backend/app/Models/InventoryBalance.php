<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InventoryBalance extends Model
{
    protected $guarded = ['*'];

    protected $appends = ['available_quantity'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    protected function availableQuantity(): Attribute
    {
        return Attribute::get(fn (): string => bcsub((string) $this->on_hand_quantity, (string) $this->reserved_quantity, 3));
    }

    protected function casts(): array
    {
        return ['on_hand_quantity' => 'decimal:3', 'reserved_quantity' => 'decimal:3'];
    }

    public function save(array $options = []): bool
    {
        throw new LogicException('Inventory balances can only be written by InventoryService.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Inventory balances cannot be deleted.');
    }
}
