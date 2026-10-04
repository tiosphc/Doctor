<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerShippingAddress;
use App\Models\DealerTier;
use App\Models\InventoryBalance;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class DealerQuickOrderService
{
    public function __construct(
        private readonly DealerEffectivePricingService $effectivePricing,
        private readonly SalesOrderService $orders,
        private readonly DealerWalletService $wallets,
        private readonly SalesPromotionService $promotions,
        private readonly WarehouseAllocationService $allocation,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<array{product_variant_id: int, quantity: string}> $items
     * @return array<string, mixed>
     */
    public function review(User $user, DealerAccount $account, array $items, array $shipping = []): array
    {
        $context = $this->effectivePricing->context($user, $account);
        $recipient = $this->recipient($account, $shipping);
        $allocation = $this->allocation->allocate($recipient['shipping_province_code'], $items);

        return $this->preview($context, $allocation, $items, $recipient);
    }

    /** @param array<string, mixed> $data */
    public function submit(User $user, DealerAccount $account, array $data): SalesOrder
    {
        $source = $data['order_source'] ?? 'quick_order';
        if (! in_array($source, ['quick_order', 'dealer_excel'], true)) {
            $this->conflict('UNSUPPORTED_ORDER_SOURCE');
        }
        $requestFingerprint = $this->requestFingerprint($user->id, $account->id, $data);

        return DB::transaction(function () use ($user, $account, $data, $requestFingerprint, $source): SalesOrder {
            $lockedAccount = DealerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $membership = DealerAccountUser::query()->where('dealer_account_id', $account->id)
                ->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($lockedAccount->status !== DealerAccount::STATUS_ACTIVE
                || $membership->status !== DealerAccountUser::STATUS_ACTIVE) {
                abort(404);
            }
            $context = $this->effectivePricing->context($user, $lockedAccount);
            $existing = SalesOrder::query()->where('creation_operation_key', $data['operation_key'])->first();
            if ($existing !== null) {
                if ($existing->sales_channel !== 'dealer' || $existing->order_source !== $source
                    || $existing->dealer_account_id !== $account->id
                    || $existing->buyer_user_id !== $user->id || $existing->creation_fingerprint !== $requestFingerprint) {
                    $this->conflict('OPERATION_KEY_CONFLICT');
                }

                return $existing;
            }
            if ($source === 'dealer_excel' && SalesOrder::query()
                ->where('dealer_account_id', $account->id)
                ->where('external_reference_normalized', $data['external_reference_normalized'])
                ->exists()) {
                $this->conflict('EXTERNAL_ORDER_REF_ALREADY_USED');
            }

            $variantIds = array_column($data['items'], 'product_variant_id');
            $productIds = ProductVariant::query()->whereIn('id', $variantIds)->pluck('product_id')->unique();
            Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
            PriceList::query()->where('pricing_context', 'dealer')->orderBy('id')->lockForUpdate()->get();
            $recipient = $this->recipient($lockedAccount, $data);
            $allocation = $this->allocation->allocate($recipient['shipping_province_code'], $data['items']);
            $warehouse = $allocation['actual'];
            if (array_key_exists('promotion_code', $data) || array_key_exists('voucher_code', $data)) {
                $this->conflict('VOUCHER_NOT_AVAILABLE_FOR_DEALER');
            }
            $review = $this->preview($context, $allocation, $data['items'], $recipient);
            if ($review['review_fingerprint'] !== $data['review_fingerprint']) {
                throw new HttpResponseException(response()->json([
                    'code' => 'DEALER_ORDER_CHANGED', 'message' => 'DEALER_ORDER_CHANGED', 'review' => $review,
                ], 409));
            }
            if (! $review['can_submit']) {
                if (! $review['wallet_sufficient']) {
                    $this->conflict('DEALER_WALLET_INSUFFICIENT_BALANCE', ['balance' => $review['wallet']['available_balance'], 'required' => $review['grand_total']]);
                }
                $line = collect($review['items'])->first(fn (array $item): bool => $item['errors'] !== []);
                $this->conflict($line['errors'][0] ?? 'DEALER_ORDER_INVALID', [
                    'sku' => $line['sku'] ?? null, 'errors' => $line['errors'] ?? [],
                    'requested' => $line['quantity'] ?? null, 'available' => $line['available_quantity'] ?? null,
                    'minimum_quantity' => $line['minimum_quantity'] ?? null,
                ]);
            }

            $orderData = [
                'operation_key' => $data['operation_key'], 'request_fingerprint' => $requestFingerprint,
                'sales_channel' => 'dealer', 'dealer_account_id' => $lockedAccount->id,
                'external_reference' => $data['external_reference'] ?? null,
                'external_reference_normalized' => $data['external_reference_normalized'] ?? null,
                'buyer_user_id' => $user->id, 'warehouse_id' => $warehouse->id, 'currency' => 'VND',
                'items' => array_map(static fn (array $line): array => [
                    'sku' => $line['sku'], 'quantity' => $line['quantity'],
                ], $review['items']),
            ];
            foreach (self::RECIPIENT_FIELDS as $field) {
                $orderData[$field] = $recipient[$field] ?? null;
            }
            $draft = $this->orders->createDraft($orderData, $user->id, $source);

            foreach ($review['promotions'] as $discountPromotion) {
                $this->promotions->redeem($draft, $discountPromotion['code']);
            }
            if ($review['gift_promotion'] !== null && $review['gift_promotion']['qualified']) {
                $this->promotions->redeem($draft, $review['gift_promotion']['code']);
            }

            $confirmed = $this->orders->confirm($draft, $this->confirmationKey($data['operation_key']), $user->id);
            $this->audit->log('AUTO_ASSIGN_WAREHOUSE', AuditLogger::MODULE_SALES_ORDER, $confirmed,
                'Dealer order warehouse assigned from delivery area and stock', metadata: [
                    'warehouse_id' => $warehouse->id, 'preferred_warehouse_id' => $allocation['preferred']->id,
                    'fallback_used' => $allocation['fallback_used'],
                    'default_area_used' => $allocation['default_area_used'], 'order_source' => $source,
                ]);
            if (bccomp($confirmed->grand_total, '0', 2) > 0) {
                $this->wallets->debitForOrder($confirmed, $data['operation_key'], $user->id);
            }
            if ($source === 'quick_order' && ($data['save_address'] ?? false)) {
                $this->saveRecipientAddress($lockedAccount, $recipient);
            }

            return $confirmed->refresh();
        }, 3);
    }

    private const RECIPIENT_FIELDS = [
        'recipient_name', 'recipient_phone', 'recipient_email', 'shipping_address_line1',
        'shipping_address_line2', 'shipping_city', 'shipping_province', 'shipping_country',
        'shipping_postal_code', 'shipping_province_code', 'shipping_ward_code', 'shipping_ward',
        'shipping_district', 'delivery_note',
    ];

    /** @param array{account: DealerAccount, membership: DealerAccountUser, tier: DealerTier, resolution: array<string, mixed>} $context
     * @param  list<array{product_variant_id: int, quantity: string}>  $items
     * @return array<string, mixed>
     */
    private function preview(array $context, array $allocation, array $items, array $recipient): array
    {
        $warehouse = $allocation['actual'];
        usort($items, static fn (array $left, array $right): int => $left['product_variant_id'] <=> $right['product_variant_id']);
        $ids = array_column($items, 'product_variant_id');
        if (count($ids) !== count(array_unique($ids))) {
            $this->conflict('DEALER_DUPLICATE_SKU');
        }
        $variants = ProductVariant::query()->with(['product.images', 'unit'])->whereIn('id', $ids)->get()->keyBy('id');
        $prices = $this->effectivePricing->catalogPrices($context, $variants->values());
        $balances = InventoryBalance::query()->where('warehouse_id', $warehouse->id)
            ->whereIn('product_variant_id', $ids)->get()->keyBy('product_variant_id');
        $subtotal = '0.00';
        $lines = [];
        $fingerprintLines = [];
        foreach ($items as $item) {
            $id = $item['product_variant_id'];
            $quantity = (string) $item['quantity'];
            $variant = $variants->get($id);
            $price = $prices[$id] ?? null;
            $images = $variant?->product?->images;
            $image = $images?->firstWhere('product_variant_id', $id)
                ?? $images?->firstWhere('is_primary', true) ?? $images?->first();
            $errors = [];
            $validQuantity = preg_match('/^[1-9][0-9]{0,14}$/', $quantity) === 1;
            if (! $validQuantity) {
                $errors[] = 'INVALID_QUANTITY';
            }
            if ($variant?->product?->gift_only) {
                $errors[] = 'GIFT_ONLY_PRODUCT_NOT_PURCHASABLE';
            }
            if ($variant === null || $variant->status !== 'active' || $variant->product->status !== 'active'
                || ! $variant->sellable_dealer || ! $variant->track_inventory) {
                $errors[] = 'DEALER_SKU_NOT_SELLABLE';
            } elseif ($price === null) {
                $errors[] = 'DEALER_PRICE_NOT_FOUND';
            } elseif ($validQuantity && bccomp($quantity, $price['minimum_quantity'], 3) < 0) {
                $errors[] = 'DEALER_MOQ_NOT_MET';
            }
            $available = $balances->get($id)?->available_quantity ?? '0.000';
            $availableForRequest = $validQuantity && bccomp($available, $quantity, 3) >= 0;
            if ($validQuantity && ! $availableForRequest) {
                $errors[] = 'INSUFFICIENT_STOCK';
            }
            $lineTotal = $price !== null && $validQuantity
                ? bcadd(bcmul($price['unit_price'], $quantity, 5), '0.005', 2) : null;
            if ($lineTotal !== null) {
                $subtotal = bcadd($subtotal, $lineTotal, 2);
            }
            $lines[] = [
                'product_variant_id' => $id, 'product_name' => $variant?->product?->name,
                'product_id' => $variant?->product_id,
                'sku' => $variant?->sku, 'variant_name' => $variant?->variant_name,
                'image_url' => $image?->url,
                'unit_name' => $variant?->unit?->name, 'unit_symbol' => $variant?->unit?->symbol,
                'unit_precision' => $variant?->unit?->decimal_precision, 'quantity' => $quantity,
                'unit_price' => $price['unit_price'] ?? null,
                'base_unit_price' => $price['base_unit_price'] ?? null,
                'minimum_quantity' => $price['minimum_quantity'] ?? null,
                'line_total' => $lineTotal, 'available_quantity' => $available,
                'available_for_requested_quantity' => $availableForRequest,
                'availability_status' => $availableForRequest ? 'available' : 'insufficient',
                'errors' => $errors,
            ];
            $fingerprintLines[] = [
                $id, $quantity, $variant?->sku, $variant?->status, $variant?->sellable_dealer,
                $variant?->track_inventory, $variant?->product?->status, $variant?->product?->name,
                $variant?->unit?->id, $variant?->unit?->decimal_precision,
                $price['price_fingerprint'] ?? null,
                array_values(array_diff($errors, ['INSUFFICIENT_STOCK'])),
            ];
        }
        $resolution = $context['resolution'];
        $account = $context['account'];
        $giftUnavailableReason = null;
        $quotes = $this->promotions->bestQuotes('dealer', $context['membership']->user_id,
            $account->id, $subtotal, array_values(array_map(static fn (array $line): array => [
                'product_variant_id' => $line['product_variant_id'], 'product_id' => $line['product_id'],
                'amount' => $line['line_total'] ?? '0.00', 'quantity' => $line['quantity'],
            ], array_filter($lines, static fn (array $line): bool => $line['product_id'] !== null))),
            $warehouse->id, $context['tier']->id, $giftUnavailableReason);
        $discountPromotions = $quotes['discounts'];
        $discountPromotion = $discountPromotions[0] ?? null;
        $giftPromotion = $quotes['gift'];
        $promotion = $discountPromotion ?? $giftPromotion;
        $discount = '0.00';
        $allocations = [];
        foreach ($discountPromotions as $discountQuote) {
            $discount = bcadd($discount, $discountQuote['discount_amount'], 2);
            $allocations += $discountQuote['allocations'];
        }
        foreach ($lines as &$line) {
            $lineDiscount = $allocations[$line['product_variant_id']] ?? '0.00';
            $line['promotion_discount_amount'] = $lineDiscount;
            $line['discounted_line_total'] = $line['line_total'] === null
                ? null : bcsub($line['line_total'], $lineDiscount, 2);
            $line['discounted_unit_price'] = $line['line_total'] === null || $line['unit_price'] === null
                ? null : bcsub($line['unit_price'], bcdiv($lineDiscount, $line['quantity'], 2), 2);
        }
        unset($line);
        $grandTotal = bcsub($subtotal, $discount, 2);
        $wallet = $this->wallets->summary($account);
        $walletSufficient = bccomp((string) $wallet['available_balance'], $grandTotal, 2) >= 0;

        return [
            'dealer_account' => $account->only(['id', 'code', 'legal_name']),
            'effective_tier' => $resolution['effective_tier'], 'tier_source' => $resolution['source'],
            'warehouse' => $warehouse->only(['id', 'code', 'name']), 'currency' => 'VND',
            'preferred_warehouse' => $allocation['preferred']->only(['id', 'code', 'name']),
            'fallback_used' => $allocation['fallback_used'],
            'default_area_used' => $allocation['default_area_used'],
            'warehouse_sufficient' => $allocation['sufficient'],
            'recipient_defaults' => $recipient,
            'wallet' => $wallet, 'wallet_sufficient' => $walletSufficient,
            'wallet_after_order' => $walletSufficient
                ? bcsub((string) $wallet['available_balance'], $grandTotal, 2) : null,
            'wallet_shortfall' => $walletSufficient ? '0.00'
                : bcsub($grandTotal, (string) $wallet['available_balance'], 2),
            'items' => $lines, 'subtotal' => $subtotal, 'discount_total' => $discount,
            'promotion' => $promotion === null ? null : array_diff_key($promotion, array_flip(['allocations'])),
            'promotions' => array_map(static fn (array $quote): array => array_diff_key($quote, array_flip(['allocations'])), $discountPromotions),
            'gift_promotion' => $giftPromotion === null ? null : array_diff_key($giftPromotion, array_flip(['allocations'])),
            'gift_unavailable_reason' => $giftUnavailableReason,
            'gift_item' => ($giftPromotion['qualified'] ?? false) ? [
                'is_gift' => true, 'product_variant_id' => $giftPromotion['gift_variant_id'],
                'product_name' => $giftPromotion['gift_product_name'], 'variant_name' => $giftPromotion['gift_variant_name'],
                'sku' => $giftPromotion['gift_sku'], 'quantity' => $giftPromotion['gift_quantity'],
                'unit_price' => '0.00', 'line_total' => '0.00',
            ] : null,
            'tax_total' => '0.00', 'shipping_total' => '0.00', 'grand_total' => $grandTotal,
            'can_submit' => $allocation['sufficient'] && $lines !== [] && collect($lines)->every(fn (array $line): bool => $line['errors'] === []) && $walletSufficient,
            'review_fingerprint' => hash('sha256', json_encode([
                $account->id, $account->code, $account->legal_name, $account->status,
                $context['membership']->id, $context['membership']->status,
                $resolution['base_tier'], $resolution['effective_tier'], $resolution['source'],
                $resolution['effective_at'], $resolution['override'],
                $warehouse->id, $warehouse->status,
                $allocation['preferred']->id, $allocation['sufficient'],
                array_diff_key($recipient, ['delivery_note' => true]), $fingerprintLines,
                array_column($discountPromotions, 'fingerprint'), $giftPromotion['fingerprint'] ?? null, $discount,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param array<string, mixed> $data */
    private function requestFingerprint(int $userId, int $accountId, array $data): string
    {
        $items = $data['items'];
        usort($items, static fn (array $left, array $right): int => $left['product_variant_id'] <=> $right['product_variant_id']);
        $recipient = [];
        foreach (self::RECIPIENT_FIELDS as $field) {
            $recipient[$field] = $data[$field] ?? null;
        }

        $parts = [$userId, $accountId, $data['order_source'] ?? 'quick_order',
            $data['external_reference_normalized'] ?? null,
            $data['shipping_address_id'] ?? null, $items, $recipient];
        if ($data['save_address'] ?? false) {
            $parts[] = 'save_address';
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $shipping @return array<string, mixed> */
    private function recipient(DealerAccount $account, array $shipping): array
    {
        if (isset($shipping['shipping_address_id'])) {
            $address = DealerShippingAddress::query()->where('dealer_account_id', $account->id)
                ->findOrFail((int) $shipping['shipping_address_id']);
            $shipping = [
                'recipient_name' => $address->recipient_name, 'recipient_phone' => $address->recipient_phone,
                'recipient_email' => $account->email,
                'shipping_address_line1' => $address->address_line,
                'shipping_address_line2' => null,
                'shipping_province_code' => $address->province_code,
                'shipping_ward_code' => $address->ward_code,
                'shipping_postal_code' => $address->postal_code,
                'shipping_district' => $address->district_legacy,
                'delivery_note' => $shipping['delivery_note'] ?? null,
            ];
        }

        return $this->allocation->normalizeRecipient(array_intersect_key($shipping, array_flip(self::RECIPIENT_FIELDS)));
    }

    /** @param array<string, mixed> $recipient */
    private function saveRecipientAddress(DealerAccount $account, array $recipient): void
    {
        $addresses = DealerShippingAddress::query()->where('dealer_account_id', $account->id)
            ->lockForUpdate()->get();
        $normalized = static fn (?string $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value ?? '') ?? ''));
        $phone = preg_replace('/[^0-9]+/', '', (string) $recipient['recipient_phone']);
        $exists = $addresses->contains(static fn (DealerShippingAddress $address): bool => $normalized($address->recipient_name) === $normalized($recipient['recipient_name'])
            && preg_replace('/[^0-9]+/', '', $address->recipient_phone) === $phone
            && $address->province_code === $recipient['shipping_province_code']
            && $address->ward_code === $recipient['shipping_ward_code']
            && $normalized($address->district_legacy) === $normalized($recipient['shipping_district'] ?? null)
            && $normalized($address->address_line) === $normalized($recipient['shipping_address_line1']));
        if ($exists) {
            return;
        }

        DealerShippingAddress::query()->create([
            'dealer_account_id' => $account->id,
            'recipient_name' => trim((string) $recipient['recipient_name']),
            'recipient_phone' => trim((string) $recipient['recipient_phone']),
            'address_line' => trim((string) $recipient['shipping_address_line1']),
            'province_code' => $recipient['shipping_province_code'],
            'ward_code' => $recipient['shipping_ward_code'],
            'district_legacy' => $recipient['shipping_district'] ?? null,
            'postal_code' => $recipient['shipping_postal_code'] ?? null,
            'is_default' => $addresses->isEmpty(),
        ]);
    }

    private function confirmationKey(string $key): string
    {
        $hash = hash('sha256', 'dealer-confirm:'.$key);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3)
            .'-8'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }

    /** @param array<string, mixed> $details */
    private function conflict(string $code, array $details = []): never
    {
        throw new HttpResponseException(response()->json([
            'code' => $code, 'message' => $code, ...$details,
        ], 409));
    }
}
