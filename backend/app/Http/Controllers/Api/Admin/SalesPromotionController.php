<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSalesPromotionRequest;
use App\Models\SalesPromotion;
use App\Services\AuditLogger;
use App\Services\SalesPromotionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesPromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:active,inactive,upcoming,expired'],
            'sales_scope' => ['nullable', 'in:retail,dealer,both'],
            'discount_type' => ['nullable', 'in:percentage,fixed_amount,buy_a_get_b']]);
        $at = now();
        $promotions = SalesPromotion::query()->with(['targets', 'giftRule', 'dealerTiers'])
            ->withCount(['redemptions as redeemed_count' => fn ($query) => $query->where('status', 'redeemed')])
            ->withSum(['giftItems as gift_units_granted' => fn ($query) => $query->where('is_gift', true)], 'quantity')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('normalized_code', 'like', '%'.Str::upper(trim($search)).'%')
                ->orWhere('name', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, function ($query, $status) use ($at): void {
                if ($status === 'inactive') {
                    $query->where('status', 'inactive');
                } elseif ($status === 'upcoming') {
                    $query->where('status', 'active')->where('starts_at', '>', $at);
                } elseif ($status === 'expired') {
                    $query->where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<', $at);
                } else {
                    $query->where('status', 'active')
                        ->where(fn ($nested) => $nested->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
                        ->where(fn ($nested) => $nested->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
                }
            })
            ->when($filters['sales_scope'] ?? null, fn ($query, $scope) => $query->where('sales_scope', $scope))
            ->when($filters['discount_type'] ?? null, fn ($query, $type) => $query->where('discount_type', $type))
            ->latest('id')->paginate(20);

        return response()->json($promotions);
    }

    public function show(SalesPromotion $promotion): JsonResponse
    {
        return response()->json(['data' => $this->details($promotion)]);
    }

    public function generateCode(): JsonResponse
    {
        do {
            $code = 'SALE'.Str::upper(Str::random(10));
        } while (SalesPromotion::query()->where('normalized_code', $code)->exists());

        return response()->json(['code' => $code]);
    }

    public function store(SaveSalesPromotionRequest $request, SalesPromotionService $promotions, AuditLogger $audit): JsonResponse
    {
        $data = $request->validated();
        try {
            $promotion = DB::transaction(function () use ($data, $request, $promotions, $audit): SalesPromotion {
                $normalized = $promotions->normalize($data['code']);
                if (SalesPromotion::query()->where('normalized_code', $normalized)->exists()) {
                    throw ValidationException::withMessages(['code' => 'Promotion code already exists.']);
                }
                $promotion = SalesPromotion::create($this->attributes($data, $normalized)
                    + ['created_by_user_id' => $request->user()->id]);
                $this->syncTargets($promotion, $data);
                $this->syncGiftRule($promotion, $data);
                $audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_SALES_PROMOTION,
                    $promotion, 'Sales promotion created', newValues: $promotion->getAttributes());

                return $promotion;
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['code' => 'Promotion code already exists.']);
            }
            throw $exception;
        }

        return response()->json(['data' => $this->details($promotion)], 201);
    }

    public function update(SaveSalesPromotionRequest $request, SalesPromotion $promotion, SalesPromotionService $promotions, AuditLogger $audit): JsonResponse
    {
        $data = $request->validated();
        try {
            $promotion = DB::transaction(function () use ($data, $promotion, $promotions, $audit): SalesPromotion {
                $locked = SalesPromotion::query()->lockForUpdate()->findOrFail($promotion->id);
                $normalized = $promotions->normalize($data['code']);
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
                $audit->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_SALES_PROMOTION,
                    $locked, 'Sales promotion updated', oldValues: $old, newValues: $locked->getAttributes());

                return $locked;
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['code' => 'Promotion code already exists.']);
            }
            throw $exception;
        }

        return response()->json(['data' => $this->details($promotion)]);
    }

    public function activate(SalesPromotion $promotion, AuditLogger $audit): JsonResponse
    {
        return $this->changeStatus($promotion, 'active', $audit);
    }

    public function deactivate(SalesPromotion $promotion, AuditLogger $audit): JsonResponse
    {
        return $this->changeStatus($promotion, 'inactive', $audit);
    }

    private function changeStatus(SalesPromotion $promotion, string $status, AuditLogger $audit): JsonResponse
    {
        $promotion = DB::transaction(function () use ($promotion, $status, $audit): SalesPromotion {
            $locked = SalesPromotion::query()->lockForUpdate()->findOrFail($promotion->id);
            if ($status === 'active' && $locked->discount_type === 'buy_a_get_b'
                && (! $locked->giftRule()->exists() || ! $this->giftRuleIsAvailable($locked))) {
                throw ValidationException::withMessages(['gift_rule' => 'Gift rule or Product is no longer available.']);
            }
            if ($locked->status !== $status) {
                $previous = $locked->status;
                $locked->update(['status' => $status]);
                $audit->log($status === 'active' ? AuditLogger::ACTION_ACTIVATE : AuditLogger::ACTION_DEACTIVATE,
                    AuditLogger::MODULE_SALES_PROMOTION, $locked, 'Sales promotion status changed',
                    oldValues: ['status' => $previous], newValues: ['status' => $status]);
            }

            return $locked;
        }, 3);

        return response()->json(['data' => $this->details($promotion)]);
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

    /** @return array<string, mixed> */
    private function details(SalesPromotion $promotion): array
    {
        $promotion->load(['targets', 'giftRule', 'dealerTiers:id,code,name']);
        $promotion->loadCount(['redemptions as redeemed_count' => fn ($query) => $query->where('status', 'redeemed')]);
        $data = $promotion->toArray();
        $data['gift_units_granted'] = $promotion->discount_type === 'buy_a_get_b'
            ? (string) DB::table('sales_order_items as item')
                ->join('sales_orders as sales_order', 'sales_order.id', '=', 'item.sales_order_id')
                ->where('item.source_promotion_id', $promotion->id)
                ->where('item.is_gift', true)->where('sales_order.order_status', '<>', 'cancelled')
                ->sum('item.quantity') : '0';
        $data['gift_units_returned'] = $promotion->discount_type === 'buy_a_get_b'
            ? (string) DB::table('sales_return_items as return_item')
                ->join('sales_order_items as order_item', 'order_item.id', '=', 'return_item.sales_order_item_id')
                ->join('sales_returns as sales_return', 'sales_return.id', '=', 'return_item.sales_return_id')
                ->where('order_item.source_promotion_id', $promotion->id)
                ->where('order_item.is_gift', true)->where('sales_return.status', 'completed')
                ->sum('return_item.quantity') : '0';
        $data['related_orders'] = $promotion->redemptions()->with('salesOrder.items')
            ->latest('id')->limit(20)->get()->map(fn ($redemption): array => [
                'order_code' => $redemption->salesOrder?->order_code,
                'sales_channel' => $redemption->sales_channel,
                'redeemed_at' => $redemption->redeemed_at,
                'redemption_status' => $redemption->status,
                'order_status' => $redemption->salesOrder?->order_status,
                'gift_sku' => $redemption->salesOrder?->items->firstWhere('is_gift', true)?->sku_snapshot,
                'gift_quantity' => $redemption->salesOrder?->items->firstWhere('is_gift', true)?->quantity,
            ])->all();

        return $data;
    }
}
