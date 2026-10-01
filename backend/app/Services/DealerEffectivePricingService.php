<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerTier;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

class DealerEffectivePricingService
{
    public function __construct(
        private readonly DealerContextService $dealerContext,
        private readonly DealerTierService $dealerTiers,
        private readonly DealerPricingService $pricing,
    ) {}

    /** @return array{account: DealerAccount, membership: DealerAccountUser, tier: DealerTier, resolution: array<string, mixed>} */
    public function context(User $user, DealerAccount $account): array
    {
        abort_unless($user->role === 'customer', 404);
        $membership = $this->dealerContext->resolve($user, $account);
        $resolution = $this->dealerTiers->resolve($account);
        $tierId = $resolution['effective_tier']['id'] ?? null;
        if ($tierId === null) {
            $this->fail('DEALER_TIER_NOT_ASSIGNED');
        }
        $tier = DealerTier::query()->findOrFail($tierId);
        if ($tier->status !== 'active') {
            $this->fail('DEALER_TIER_INACTIVE');
        }

        return ['account' => $account, 'membership' => $membership, 'tier' => $tier, 'resolution' => $resolution];
    }

    /** @return array<string, mixed> */
    public function quote(User $user, DealerAccount $account, int $variantId, string $quantity): array
    {
        $context = $this->context($user, $account);
        $variant = ProductVariant::query()->with(['product', 'unit'])->findOrFail($variantId);
        if ($variant->status !== 'active' || ! $variant->sellable_dealer || $variant->product->status !== 'active'
            || $variant->product->gift_only) {
            $this->fail('DEALER_SKU_NOT_SELLABLE');
        }
        $price = $this->pricing->resolve($variant, $context['tier'], $quantity, false);

        return [
            'dealer_account' => $account->only(['id', 'code', 'legal_name']),
            'base_tier' => $context['resolution']['base_tier'],
            'effective_tier' => $context['resolution']['effective_tier'],
            'pricing_source' => $context['resolution']['source'],
            'product' => $variant->product->only(['id', 'product_code', 'name', 'slug']),
            'variant' => ['id' => $variant->id, 'sku' => $variant->sku, 'variant_name' => $variant->variant_name,
                'unit' => $variant->unit?->name, 'unit_symbol' => $variant->unit?->symbol],
            'quantity' => $quantity,
            'warehouse' => null,
            'base_unit_price' => $price['base_unit_price'],
            'unit_price' => $price['unit_price'],
            'line_total' => $price['line_total'],
            'currency' => $price['currency'],
            'minimum_quantity' => $price['minimum_quantity'],
            'meets_moq' => $price['meets_moq'],
            'price_fingerprint' => $this->fingerprint($context, $variant, $price),
        ];
    }

    /** @param array{account: DealerAccount, membership: DealerAccountUser, tier: DealerTier, resolution: array<string, mixed>} $context
     * @param  Collection<int, ProductVariant>  $variants
     * @return array<int, array<string, mixed>>
     */
    public function catalogPrices(array $context, Collection $variants): array
    {
        $prices = $this->pricing->pricesFor($variants, $context['tier']);
        $result = [];
        foreach ($variants as $variant) {
            $price = $prices[$variant->id] ?? null;
            if ($price !== null) {
                $result[$variant->id] = [
                    'unit_price' => $price['unit_price'], 'currency' => $price['currency'],
                    'base_unit_price' => $price['base_unit_price'],
                    'minimum_quantity' => $price['minimum_quantity'],
                    'price_fingerprint' => $this->fingerprint($context, $variant, $price),
                ];
            }
        }

        return $result;
    }

    /** @param array{account: DealerAccount, tier: DealerTier, resolution: array<string, mixed>} $context
     * @param  array<string, mixed>  $price
     */
    private function fingerprint(array $context, ProductVariant $variant, array $price): string
    {
        return hash('sha256', json_encode([
            $context['account']->id, $context['tier']->id, $context['resolution']['source'],
            $context['resolution']['effective_at'], $context['resolution']['override']['id'] ?? null,
            $variant->id, $price['price_list_id'], $price['price_list_item_id'],
            $price['unit_price'], $price['minimum_quantity'], $price['currency'],
            $price['price_list_updated_at'], $price['price_item_updated_at'],
            $price['price_list_effective_from'], $price['price_list_effective_to'],
            $price['price_item_effective_from'], $price['price_item_effective_to'],
        ], JSON_THROW_ON_ERROR));
    }

    private function fail(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
