<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    protected $fillable = ['order_code', 'creation_operation_key', 'creation_fingerprint', 'sales_channel', 'order_source', 'buyer_user_id', 'warehouse_id', 'currency', 'recipient_name', 'recipient_phone', 'recipient_email', 'shipping_address_line1', 'shipping_address_line2', 'shipping_city', 'shipping_province', 'shipping_country', 'shipping_postal_code', 'delivery_note', 'order_status', 'payment_status', 'fulfillment_status', 'pricing_context_snapshot', 'subtotal', 'discount_total', 'tax_total', 'shipping_total', 'grand_total', 'price_resolution_fingerprint', 'created_by', 'confirmed_by', 'confirmed_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason', 'completed_at'];

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'shipping_total' => 'decimal:2', 'grand_total' => 'decimal:2', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
