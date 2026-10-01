<?php

namespace App\Http\Resources;

use App\Services\OrderPaymentSummaryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealerSalesOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment = app(OrderPaymentSummaryService::class)->summary($this->resource);

        return [
            'id' => $this->id,
            'order_code' => $this->order_code,
            'sales_channel' => $this->sales_channel,
            'order_source' => $this->order_source,
            'external_reference' => $this->external_reference,
            'dealer_account' => [
                'id' => $this->dealer_account_id,
                'code' => $this->dealer_code_snapshot,
                'legal_name' => $this->dealer_name_snapshot,
            ],
            'effective_tier' => [
                'code' => $this->effective_tier_code_snapshot,
                'name' => $this->effective_tier_name_snapshot,
                'source' => $this->tier_source_snapshot,
            ],
            'placed_by' => $this->whenLoaded('buyer', fn (): ?array => $this->buyer
                ? $this->buyer->only(['id', 'name']) : null),
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->warehouse
                ? $this->warehouse->only(['code', 'name']) : null),
            'currency' => $this->currency,
            'order_status' => $this->order_status,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'paid_amount' => $payment['paid_amount'],
            'outstanding_amount' => $payment['outstanding_amount'],
            'refunded_amount' => $payment['refunded_amount'],
            'refundable_amount' => $payment['refundable_amount'],
            'net_settled_amount' => $payment['net_settled_amount'],
            'refund_status' => $payment['refund_status'],
            'refunds' => ($this->resource->relationLoaded('refunds') ? $this->refunds : collect())
                ->map(fn ($refund): array => ['refund_code' => $refund->refund_code,
                    'amount' => $refund->amount, 'method' => $refund->refund_method,
                    'status' => $refund->status, 'completed_at' => $refund->completed_at]),
            'returns' => $this->whenLoaded('salesReturns', fn (): mixed => $this->salesReturns
                ->map(fn ($entry): array => ['return_code' => $entry->return_code,
                    'completed_at' => $entry->completed_at,
                    'items' => $entry->items->map(fn ($item): array => [
                        'quantity' => $item->quantity, 'restock_quantity' => $item->restock_quantity,
                        'non_restock_quantity' => $item->non_restock_quantity])])),
            'fulfillment_status' => $this->fulfillment_status,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'recipient_email' => $this->recipient_email,
            'shipping_address_line1' => $this->shipping_address_line1,
            'shipping_address_line2' => $this->shipping_address_line2,
            'shipping_city' => $this->shipping_city,
            'shipping_district' => $this->shipping_district,
            'shipping_province' => $this->shipping_province,
            'shipping_province_code' => $this->shipping_province_code,
            'shipping_ward_code' => $this->shipping_ward_code,
            'shipping_ward' => $this->shipping_ward,
            'shipping_country' => $this->shipping_country,
            'shipping_postal_code' => $this->shipping_postal_code,
            'delivery_note' => $this->delivery_note,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'promotion' => $this->promotion_code_snapshot === null ? null : [
                'code' => $this->promotion_code_snapshot, 'name' => $this->promotion_name_snapshot,
                'discount_type' => $this->promotion_discount_type_snapshot,
                'discount_value' => $this->promotion_discount_value_snapshot,
                'discount_amount' => $this->discount_total,
                'gift' => $this->promotion_gift_snapshot,
            ],
            'tax_total' => $this->tax_total,
            'shipping_total' => $this->shipping_total,
            'grand_total' => $this->grand_total,
            'item_count' => $this->items_count ?? $this->whenLoaded('items', fn (): int => $this->items->count()),
            'total_quantity' => $this->total_quantity ?? null,
            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name_snapshot,
                'product_code' => $item->product_code_snapshot,
                'sku' => $item->sku_snapshot,
                'variant_name' => $item->variant_name_snapshot,
                'unit_code' => $item->unit_code_snapshot,
                'unit_name' => $item->unit_name_snapshot,
                'quantity' => $item->quantity,
                'is_gift' => $item->is_gift,
                'source_promotion_id' => $item->source_promotion_id,
                'unit_price' => $item->unit_price_snapshot,
                'base_amount' => $item->base_amount,
                'discount_amount' => $item->discount_amount,
                'minimum_quantity' => $item->minimum_quantity_snapshot,
                'line_total' => $item->line_total,
            ])->all()),
            'created_at' => $this->created_at,
            'confirmed_at' => $this->confirmed_at,
            'cancelled_at' => $this->cancelled_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
