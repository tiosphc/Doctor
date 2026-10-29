<?php

namespace App\Services;

use App\Models\SalesPromotion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesPromotionWriteService
{
    public function __construct(
        private readonly SalesPromotionService $promotions,
        private readonly AuditLogger $audit,
    ) {}

    public function suggestCode(): string
    {
        do {
            $code = 'SALE'.Str::upper(Str::random(10));
        } while (SalesPromotion::query()->where('normalized_code', $code)->exists());

        return $code;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): SalesPromotion
    {
        try {
            return DB::transaction(function () use ($data, $actor): SalesPromotion {
                $normalized = $this->promotions->normalize($data['code']);
                if (SalesPromotion::query()->where('normalized_code', $normalized)->exists()) {
                    throw ValidationException::withMessages(['code' => 'Promotion code already exists.']);
                }
                $this->promotions->assertDiscountAvailability($data);
                $promotion = SalesPromotion::create($this->attributes($data, $normalized)
                    + ['created_by_user_id' => $actor->id]);
                $this->syncTargets($promotion, $data);
                $this->syncGiftRule($promotion, $data);
                $this->audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_SALES_PROMOTION,
                    $promotion, 'Sales promotion created', newValues: $promotion->getAttributes());

                return $promotion;
            }, 3);
        } catch (QueryException $exception) {
            $this->handleDuplicateCode($exception);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(SalesPromotion $promotion, array $data): SalesPromotion
    {
        try {
            return DB::transaction(function () use ($data, $promotion): SalesPromotion {
                $locked = SalesPromotion::query()->lockForUpdate()->findOrFail($promotion->id);
                $this->promotions->assertDiscountAvailability($data, $locked->id);
                $normalized = $this->promotions->normalize($data['code']);
                if ($normalized !== $locked->normalized_code && $locked->redemptions()->exists()) {
                    throw new HttpResponseException(response()->json([
                        'code' => 'PROMOTION_CODE_IMMUTABLE', 'message' => 'PROMOTION_CODE_IMMUTABLE',
                    ], 409));
                }
                if (SalesPromotion::query()->where('normalized_code', $normalized)->whereKeyNot($locked->id)->exists()) {
                    throw ValidationException::withMessages(['code' => 'Promotion code already exists.']);
                }
                $old = $locked->getAttributes();
                $locked->update($this->attributes($data, $normalized));
                $this->syncTargets($locked, $data);
                $this->syncGiftRule($locked, $data);
                $this->audit->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_SALES_PROMOTION,
                    $locked, 'Sales promotion updated', oldValues: $old, newValues: $locked->getAttributes());

                return $locked;
            }, 3);
        } catch (QueryException $exception) {
            $this->handleDuplicateCode($exception);
            throw $exception;
        }
    }

    public function setStatus(SalesPromotion $promotion, string $status): SalesPromotion
    {
        return DB::transaction(function () use ($promotion, $status): SalesPromotion {
            $locked = SalesPromotion::query()->lockForUpdate()->findOrFail($promotion->id);
            if ($status === 'active') {
                $this->promotions->assertDiscountAvailability([
                    'sales_scope' => $locked->sales_scope,
                    'discount_type' => $locked->discount_type,
                    'status' => 'active',
                    'product_ids' => $locked->targets()->whereNotNull('product_id')->pluck('product_id')->all(),
                    'category_ids' => $locked->targets()->whereNotNull('product_category_id')->pluck('product_category_id')->all(),
                    'dealer_tier_ids' => $locked->dealerTiers()->pluck('dealer_tiers.id')->all(),
                    'starts_at' => $locked->starts_at?->toDateTimeString(),
                    'ends_at' => $locked->ends_at?->toDateTimeString(),
                ], $locked->id);
            }
            if ($status === 'active' && $locked->discount_type === 'buy_a_get_b'
                && (! $locked->giftRule()->exists() || ! $this->giftRuleIsAvailable($locked))) {
                throw ValidationException::withMessages(['gift_rule' => 'Gift rule or Product is no longer available.']);
            }
            if ($locked->status !== $status) {
                $previous = $locked->status;
                $locked->update(['status' => $status]);
                $this->audit->log($status === 'active' ? AuditLogger::ACTION_ACTIVATE : AuditLogger::ACTION_DEACTIVATE,
                    AuditLogger::MODULE_SALES_PROMOTION, $locked, 'Sales promotion status changed',
                    oldValues: ['status' => $previous], newValues: ['status' => $status]);
            }

            return $locked;
        }, 3);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, string $normalized): array
    {
        return ['code' => $data['code'], 'normalized_code' => $normalized, 'name' => $data['name'],
            'description' => $data['description'] ?? null, 'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_type'] === 'buy_a_get_b' ? '0.00' : $data['discount_value'],
            'max_discount_amount' => $data['discount_type'] === 'buy_a_get_b' ? null : ($data['max_discount_amount'] ?? null),
            'minimum_order_amount' => $data['minimum_order_amount'] ?? '0.00',
            'sales_scope' => $data['sales_scope'], 'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null, 'total_usage_limit' => $data['total_usage_limit'] ?? null,
            'per_buyer_usage_limit' => $data['per_buyer_usage_limit'] ?? null, 'status' => $data['status']];
    }

    /** @param array<string, mixed> $data */
    private function syncTargets(SalesPromotion $promotion, array $data): void
    {
        $promotion->targets()->delete();
        foreach ($data['product_ids'] ?? [] as $id) {
            $promotion->targets()->create(['product_id' => $id]);
        }
        foreach ($data['category_ids'] ?? [] as $id) {
            $promotion->targets()->create(['product_category_id' => $id]);
        }
    }

    /** @param array<string, mixed> $data */
    private function syncGiftRule(SalesPromotion $promotion, array $data): void
    {
        if ($promotion->discount_type !== 'buy_a_get_b') {
            $promotion->giftRule()->delete();
        } else {
            $promotion->giftRule()->updateOrCreate([], $data['gift_rule']);
        }
        $promotion->dealerTiers()->sync($promotion->sales_scope === 'retail' ? [] : ($data['dealer_tier_ids'] ?? []));
    }

    private function giftRuleIsAvailable(SalesPromotion $promotion): bool
    {
        $rule = $promotion->giftRule()->with(['buyProduct', 'giftProduct', 'buyVariant', 'giftVariant'])->first();
        if ($rule === null || $rule->buyProduct?->status !== 'active' || $rule->buyProduct->gift_only
            || $rule->giftProduct?->status !== 'active'
            || (! $rule->giftProduct->can_be_gift && $rule->gift_product_id !== $rule->buy_product_id)
            || $rule->giftVariant?->status !== 'active' || ! $rule->giftVariant->track_inventory
            || $rule->giftVariant->product_id !== $rule->gift_product_id) {
            return false;
        }
        $channels = $promotion->sales_scope === 'both' ? ['retail', 'dealer'] : [$promotion->sales_scope];
        foreach ($channels as $channel) {
            if ($rule->buy_variant_id !== null) {
                if ($rule->buyVariant?->status !== 'active'
                    || $rule->buyVariant->product_id !== $rule->buy_product_id
                    || ! $rule->buyVariant->track_inventory
                    || ! $rule->buyVariant->{'sellable_'.$channel}) {
                    return false;
                }
            } elseif (! DB::table('product_variants')->where('product_id', $rule->buy_product_id)
                ->where('status', 'active')->where('track_inventory', true)
                ->where('sellable_'.$channel, true)->exists()) {
                return false;
            }
        }

        return true;
    }

    private function handleDuplicateCode(QueryException $exception): void
    {
        if (($exception->errorInfo[1] ?? null) === 1062) {
            throw ValidationException::withMessages(['code' => 'Promotion code already exists.']);
        }
    }
}
