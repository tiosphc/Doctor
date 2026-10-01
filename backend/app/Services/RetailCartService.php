<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\InventoryBalance;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetailCartService
{
    public function __construct(
        private readonly RetailPricingService $pricing,
        private readonly SalesPromotionService $promotions,
        private readonly SalesVoucherService $vouchers,
    ) {}

    /** @return array<string, mixed> */
    public function show(int $userId): array
    {
        $cart = DB::transaction(fn (): Cart => $this->lockedActiveCart($userId, true), 3);

        return $this->preview($cart);
    }

    /** @return array<string, mixed> */
    public function add(int $userId, int $variantId, string $quantity): array
    {
        $cart = DB::transaction(function () use ($userId, $variantId, $quantity): Cart {
            $cart = $this->lockedActiveCart($userId, true);
            $variant = ProductVariant::query()->with(['product', 'unit'])->findOrFail($variantId);
            $item = $cart->items()->where('product_variant_id', $variantId)->lockForUpdate()->first();
            $newQuantity = $item === null ? $quantity : bcadd($item->quantity, $quantity, 3);
            $this->assertOrderable($variant, $newQuantity);
            if ($item === null) {
                $cart->items()->create(['product_variant_id' => $variantId, 'quantity' => $newQuantity]);
            } else {
                $item->update(['quantity' => $newQuantity]);
            }

            return $cart;
        }, 3);

        return $this->preview($cart);
    }

    /** @return array<string, mixed> */
    public function update(int $userId, int $itemId, string $quantity): array
    {
        $cart = DB::transaction(function () use ($userId, $itemId, $quantity): Cart {
            $cart = $this->lockedActiveCart($userId, false);
            if ($cart === null) {
                abort(404);
            }
            $item = $cart->items()->with('variant.product', 'variant.unit')->lockForUpdate()->findOrFail($itemId);
            $this->assertOrderable($item->variant, $quantity);
            $item->update(['quantity' => $quantity]);

            return $cart;
        }, 3);

        return $this->preview($cart);
    }

    /** @return array<string, mixed> */
    public function remove(int $userId, int $itemId): array
    {
        $cart = DB::transaction(function () use ($userId, $itemId): Cart {
            $cart = $this->lockedActiveCart($userId, false);
            if ($cart === null) {
                abort(404);
            }
            $cart->items()->whereKey($itemId)->firstOrFail()->delete();

            return $cart;
        }, 3);

        return $this->preview($cart);
    }

    /** @return array<string, mixed> */
    public function clear(int $userId): array
    {
        $cart = DB::transaction(function () use ($userId): Cart {
            $cart = $this->lockedActiveCart($userId, true);
            $cart->items()->delete();
            $cart->update(['voucher_code' => null]);

            return $cart;
        }, 3);

        return $this->preview($cart);
    }

    /** @return array<string, mixed> */
    public function applyVoucher(int $userId, ?string $code): array
    {
        $cart = DB::transaction(function () use ($userId, $code): Cart {
            $cart = $this->lockedActiveCart($userId, true);
            if ($code !== null && trim($code) !== '') {
                $cart->update(['voucher_code' => $this->vouchers->normalize($code)]);
                $preview = $this->preview($cart);
                if ($preview['voucher_error'] !== null) {
                    $this->conflict($preview['voucher_error']);
                }
            } else {
                $cart->update(['voucher_code' => null]);
            }

            return $cart;
        }, 3);

        return $this->preview($cart);
    }

    public function lockedActiveCart(int $userId, bool $create): ?Cart
    {
        User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
        $cart = Cart::query()->where('user_id', $userId)->where('context', 'retail')
            ->where('status', 'active')->lockForUpdate()->first();
        if ($cart === null && $create) {
            $cart = Cart::create(['user_id' => $userId, 'context' => 'retail', 'status' => 'active']);
        }

        return $cart;
    }

    public function defaultWarehouse(bool $lock = false): Warehouse
    {
        $query = Warehouse::query()->where('status', 'active')->where('is_default_sales', true)->orderBy('id')->limit(2);
        $warehouses = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($warehouses->isEmpty()) {
            $this->conflict('CHECKOUT_WAREHOUSE_NOT_CONFIGURED');
        }
        if ($warehouses->count() !== 1) {
            $this->conflict('CHECKOUT_WAREHOUSE_AMBIGUOUS');
        }

        return $warehouses->first();
    }

    /** @return array<string, mixed> */
    public function preview(Cart $cart, ?Warehouse $warehouse = null): array
    {
        $cart->load(['items.variant.product.images', 'items.variant.unit']);
        if ($warehouse === null) {
            $warehouse = Warehouse::query()->where('status', 'active')->where('is_default_sales', true)->first();
        }
        $variantIds = $cart->items->pluck('product_variant_id')->all();
        $balances = $warehouse === null ? collect() : InventoryBalance::query()
            ->where('warehouse_id', $warehouse->id)->whereIn('product_variant_id', $variantIds)
            ->get()->keyBy('product_variant_id');
        $subtotal = '0.00';
        $lines = [];
        foreach ($cart->items->sortBy('product_variant_id') as $item) {
            $variant = $item->variant;
            $product = $variant->product;
            $errors = [];
            if ($product->status !== 'active') {
                $errors[] = 'PRODUCT_INACTIVE';
            }
            if ($product->gift_only) {
                $errors[] = 'GIFT_ONLY_PRODUCT_NOT_PURCHASABLE';
            }
            if ($variant->status !== 'active') {
                $errors[] = 'SKU_INACTIVE';
            }
            if (! $variant->sellable_retail) {
                $errors[] = 'SKU_NOT_RETAIL_SELLABLE';
            }
            if (! $variant->track_inventory) {
                $errors[] = 'RETAIL_SKU_NOT_ORDERABLE';
            }
            $price = null;
            if ($errors === []) {
                try {
                    $price = $this->pricing->resolve($variant, 'VND', quantity: $item->quantity);
                } catch (HttpResponseException $exception) {
                    $errors[] = $exception->getResponse()->getData(true)['code'] ?? 'PRICE_NOT_FOUND';
                }
            }
            $available = $warehouse === null ? null : ($balances->get($variant->id)?->available_quantity ?? '0.000');
            if ($warehouse !== null && bccomp($available, $item->quantity, 3) < 0) {
                $errors[] = bccomp($available, '0', 3) <= 0 ? 'OUT_OF_STOCK' : 'INSUFFICIENT_STOCK';
            }
            $lineTotal = $price === null ? null : bcadd(bcmul($price['unit_price'], $item->quantity, 5), '0.005', 2);
            if ($lineTotal !== null) {
                $subtotal = bcadd($subtotal, $lineTotal, 2);
            }
            $image = $product->images->first(fn ($image): bool => $image->product_variant_id === $variant->id)
                ?? $product->images->first(fn ($image): bool => $image->product_variant_id === null)
                ?? $product->images->first();
            $lines[] = [
                'id' => $item->id, 'product_variant_id' => $variant->id, 'sku' => $variant->sku,
                'product_id' => $product->id, 'product_name' => $product->name, 'product_slug' => $product->slug,
                'variant_name' => $variant->variant_name, 'unit_name' => $variant->unit->name,
                'unit_symbol' => $variant->unit->symbol, 'unit_precision' => $variant->unit->decimal_precision,
                'image_url' => $image?->url, 'quantity' => $item->quantity,
                'retail_price' => $price, 'line_total' => $lineTotal,
                'available_quantity' => $available, 'errors' => $errors,
            ];
        }
        $fingerprintLines = array_map(fn (array $line): array => [
            $line['product_variant_id'], $line['sku'], $line['quantity'], $line['retail_price'],
            array_values(array_diff($line['errors'], ['OUT_OF_STOCK', 'INSUFFICIENT_STOCK'])),
        ], $lines);

        $promotionLines = array_values(array_filter(array_map(static fn (array $line): ?array => $line['line_total'] === null ? null : [
            'product_variant_id' => $line['product_variant_id'], 'product_id' => $line['product_id'],
            'amount' => $line['line_total'], 'quantity' => $line['quantity'],
        ], $lines)));
        $giftUnavailableReason = null;
        $promotion = $this->promotions->bestQuote('retail', $cart->user_id, null,
            $subtotal, $promotionLines, $warehouse?->id, null, $giftUnavailableReason);
        $voucher = null;
        $voucherError = null;
        if ($cart->voucher_code !== null) {
            try {
                $voucher = $this->vouchers->quote($cart->voucher_code, 'retail', $cart->user_id,
                    bcsub($subtotal, $promotion['discount_amount'] ?? '0.00', 2));
            } catch (HttpResponseException $exception) {
                $voucherError = $exception->getResponse()->getData(true)['code'] ?? 'VOUCHER_NOT_FOUND';
            }
        }
        $discount = bcadd($promotion['discount_amount'] ?? '0.00', $voucher['discount_amount'] ?? '0.00', 2);
        $total = bcsub($subtotal, $discount, 2);

        return [
            'id' => $cart->id, 'items' => $lines, 'item_count' => count($lines),
            'warehouse' => $warehouse?->only(['id', 'code', 'name']),
            'subtotal' => $subtotal, 'discount_total' => $discount, 'tax_total' => '0.00',
            'shipping_total' => '0.00', 'grand_total' => $total, 'currency' => 'VND',
            'voucher_code' => $cart->voucher_code, 'voucher_error' => $voucherError,
            'voucher_percent' => ($voucher['discount_type'] ?? null) === 'percentage' ? $voucher['discount_value'] : null,
            'voucher' => $voucher,
            'promotion' => $promotion === null ? null : array_diff_key($promotion, array_flip(['allocations'])),
            'gift_unavailable_reason' => $giftUnavailableReason,
            'gift_item' => ($promotion['qualified'] ?? false) ? [
                'is_gift' => true, 'product_variant_id' => $promotion['gift_variant_id'],
                'product_name' => $promotion['gift_product_name'], 'variant_name' => $promotion['gift_variant_name'],
                'sku' => $promotion['gift_sku'], 'quantity' => $promotion['gift_quantity'],
                'unit_price' => '0.00', 'line_total' => '0.00',
            ] : null,
            'can_checkout' => $warehouse !== null && count($lines) > 0
                && $voucherError === null && collect($lines)->every(fn (array $line): bool => $line['errors'] === []),
            'review_fingerprint' => hash('sha256', json_encode([$cart->id, $cart->user_id, $warehouse?->id,
                'VND', $fingerprintLines, $cart->voucher_code, $promotion['fingerprint'] ?? null,
                $voucher['fingerprint'] ?? null, $discount], JSON_THROW_ON_ERROR)),
        ];
    }

    public function assertOrderable(ProductVariant $variant, string $quantity): void
    {
        if ($variant->product->gift_only) {
            $this->conflict('GIFT_ONLY_PRODUCT_NOT_PURCHASABLE');
        }
        if ($variant->product->status !== 'active' || $variant->status !== 'active'
            || ! $variant->sellable_retail || ! $variant->track_inventory) {
            $this->conflict('RETAIL_SKU_NOT_ORDERABLE');
        }
        $wholeQuantity = preg_replace('/\.0{1,3}$/', '', $quantity);
        if (preg_match('/^[1-9][0-9]{0,14}$/', $wholeQuantity) !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be a positive whole number.']);
        }
        $this->pricing->resolve($variant, 'VND', quantity: $quantity);
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
