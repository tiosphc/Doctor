<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class RetailCheckoutService
{
    public function __construct(
        private readonly RetailCartService $carts,
        private readonly SalesOrderService $orders,
    ) {}

    /** @return array<string, mixed> */
    public function review(int $userId): array
    {
        $cart = Cart::query()->where('user_id', $userId)->where('context', 'retail')->where('status', 'active')->first();
        if ($cart === null || ! $cart->items()->exists()) {
            $this->conflict('CART_EMPTY');
        }
        $warehouse = $this->carts->defaultWarehouse();
        $preview = $this->carts->preview($cart, $warehouse);
        $user = User::query()->findOrFail($userId);
        $preview['recipient_defaults'] = ['recipient_name' => $user->name, 'recipient_phone' => $user->phone, 'recipient_email' => $user->email];

        return $preview;
    }

    /** @param array<string, mixed> $data */
    public function checkout(int $userId, array $data): SalesOrder
    {
        $payload = $data;
        unset($payload['checkout_operation_key']);
        ksort($payload);
        $payloadFingerprint = hash('sha256', json_encode([$userId, $payload], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($userId, $data, $payloadFingerprint): SalesOrder {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $replay = Cart::query()->where('checkout_operation_key', $data['checkout_operation_key'])->lockForUpdate()->first();
            if ($replay !== null) {
                if ($replay->user_id !== $userId || $replay->checkout_payload_fingerprint !== $payloadFingerprint) {
                    $this->conflict('CHECKOUT_OPERATION_CONFLICT');
                }

                return $replay->convertedOrder()->firstOrFail();
            }
            $cart = Cart::query()->where('user_id', $userId)->where('context', 'retail')
                ->where('status', 'active')->lockForUpdate()->first();
            if ($cart === null) {
                $this->conflict('CART_EMPTY');
            }
            $variantIds = $cart->items()->orderBy('product_variant_id')->lockForUpdate()->pluck('product_variant_id');
            if ($variantIds->isEmpty()) {
                $this->conflict('CART_EMPTY');
            }
            $productIds = ProductVariant::query()->whereIn('id', $variantIds)
                ->orderBy('id')->lockForUpdate()->pluck('product_id')->unique();
            Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
            PriceList::query()->where('pricing_context', 'retail')->orderBy('id')->lockForUpdate()->get();
            $warehouse = $this->carts->defaultWarehouse(true);
            $review = $this->carts->preview($cart, $warehouse);
            if ($review['review_fingerprint'] !== $data['checkout_review_fingerprint']) {
                throw new HttpResponseException(response()->json([
                    'code' => 'CHECKOUT_CHANGED', 'message' => 'CHECKOUT_CHANGED', 'review' => $review,
                ], 409));
            }
            if (! $review['can_checkout']) {
                if ($review['voucher_error'] !== null) {
                    $this->conflict('VOUCHER_INVALID');
                }
                $line = collect($review['items'])->first(fn (array $item): bool => $item['errors'] !== []);
                $code = in_array('INSUFFICIENT_STOCK', $line['errors'], true) || in_array('OUT_OF_STOCK', $line['errors'], true)
                    ? 'INSUFFICIENT_STOCK' : 'CHECKOUT_ITEM_INVALID';
                throw new HttpResponseException(response()->json([
                    'code' => $code, 'message' => $code, 'sku' => $line['sku'],
                    'requested' => $line['quantity'], 'available' => $line['available_quantity'],
                    'errors' => $line['errors'],
                ], 409));
            }
            $items = array_map(fn (array $line): array => ['sku' => $line['sku'], 'quantity' => $line['quantity']], $review['items']);
            $orderData = [
                'operation_key' => $data['checkout_operation_key'], 'sales_channel' => 'retail',
                'buyer_user_id' => $userId, 'warehouse_id' => $warehouse->id, 'currency' => 'VND',
                'recipient_name' => $data['recipient_name'], 'recipient_phone' => $data['recipient_phone'],
                'recipient_email' => $data['recipient_email'] ?? null,
                'shipping_address_line1' => $data['shipping_address_line1'],
                'shipping_address_line2' => $data['shipping_address_line2'] ?? null,
                'shipping_city' => $data['shipping_city'], 'shipping_province' => $data['shipping_province'],
                'shipping_district' => $data['shipping_district'],
                'shipping_country' => $data['shipping_country'],
                'payment_method' => $data['payment_method'],
                'shipping_postal_code' => $data['shipping_postal_code'] ?? null,
                'delivery_note' => $data['delivery_note'] ?? null,
                'items' => $items,
            ];
            $order = $this->orders->createDraft($orderData, $userId, 'cart');
            $promotionCodes = array_column($review['promotions'], 'code');
            $primaryPromotionCode = array_shift($promotionCodes);
            $order = $this->orders->submitRetail($order,
                substr(hash('sha256', 'submit:'.$data['checkout_operation_key']), 0, 36),
                $userId, $primaryPromotionCode,
                $review['voucher']['code'] ?? null,
                ($review['gift_promotion']['qualified'] ?? false) ? $review['gift_promotion']['code'] : null,
                $promotionCodes);
            $cart->update([
                'status' => 'converted', 'converted_sales_order_id' => $order->id,
                'checkout_operation_key' => $data['checkout_operation_key'],
                'checkout_payload_fingerprint' => $payloadFingerprint,
            ]);

            return $order;
        }, 3);
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
