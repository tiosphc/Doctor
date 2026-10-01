<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesOrder extends Model
{
    protected $fillable = ['order_code', 'creation_operation_key', 'creation_fingerprint', 'sales_channel', 'order_source', 'external_reference', 'external_reference_normalized', 'buyer_user_id', 'dealer_account_id', 'dealer_code_snapshot', 'dealer_name_snapshot', 'effective_tier_id_snapshot', 'effective_tier_code_snapshot', 'effective_tier_name_snapshot', 'tier_source_snapshot', 'tier_override_id_snapshot', 'warehouse_id', 'currency', 'recipient_name', 'recipient_phone', 'recipient_email', 'shipping_address_line1', 'shipping_address_line2', 'shipping_city', 'shipping_district', 'shipping_province', 'shipping_province_code', 'shipping_ward_code', 'shipping_ward', 'shipping_country', 'shipping_postal_code', 'delivery_note', 'order_status', 'payment_status', 'payment_method', 'voucher_id', 'voucher_code_snapshot', 'sales_voucher_id', 'sales_voucher_code_snapshot', 'sales_voucher_discount_snapshot', 'sales_promotion_id', 'promotion_code_snapshot', 'promotion_name_snapshot', 'promotion_discount_type_snapshot', 'promotion_discount_value_snapshot', 'promotion_gift_snapshot', 'fulfillment_status', 'pricing_context_snapshot', 'subtotal', 'discount_total', 'tax_total', 'shipping_total', 'grand_total', 'price_resolution_fingerprint', 'created_by', 'confirmed_by', 'confirmed_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason', 'completed_at'];

    public function permitsPromotionGift(ProductVariant $variant, ?int $sourcePromotionId): bool
    {
        if ($sourcePromotionId === null || $sourcePromotionId !== $this->sales_promotion_id) {
            return false;
        }

        if ($variant->product->can_be_gift) {
            return true;
        }

        $gift = $this->promotion_gift_snapshot;

        return is_array($gift)
            && (int) ($gift['buy_product_id'] ?? 0) === $variant->product_id
            && (int) ($gift['gift_product_id'] ?? 0) === $variant->product_id
            && (int) ($gift['gift_variant_id'] ?? 0) === $variant->id;
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SalesOrderHistory::class)->orderBy('id');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function refundAllocations(): HasManyThrough
    {
        return $this->hasManyThrough(RefundAllocation::class, PaymentAllocation::class, 'sales_order_id', 'payment_allocation_id');
    }

    public function salesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    public function walletTransaction(): HasOne
    {
        return $this->hasOne(DealerWalletTransaction::class);
    }

    public function promotionRedemption(): HasOne
    {
        return $this->hasOne(SalesPromotionRedemption::class);
    }

    public function salesVoucherRedemption(): HasOne
    {
        return $this->hasOne(SalesVoucherRedemption::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function dealerAccount(): BelongsTo
    {
        return $this->belongsTo(DealerAccount::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'shipping_total' => 'decimal:2', 'grand_total' => 'decimal:2', 'promotion_discount_value_snapshot' => 'decimal:2', 'sales_voucher_discount_snapshot' => 'decimal:2', 'promotion_gift_snapshot' => 'array', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
