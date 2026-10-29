<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSalesPromotionRequest;
use App\Models\SalesPromotion;
use App\Services\SalesPromotionWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public function generateCode(SalesPromotionWriteService $writer): JsonResponse
    {
        return response()->json(['code' => $writer->suggestCode()]);
    }

    public function store(SaveSalesPromotionRequest $request, SalesPromotionWriteService $writer): JsonResponse
    {
        $promotion = $writer->create($request->validated(), $request->user());

        return response()->json(['data' => $this->details($promotion)], 201);
    }

    public function update(SaveSalesPromotionRequest $request, SalesPromotion $promotion, SalesPromotionWriteService $writer): JsonResponse
    {
        $promotion = $writer->update($promotion, $request->validated());

        return response()->json(['data' => $this->details($promotion)]);
    }

    public function activate(SalesPromotion $promotion, SalesPromotionWriteService $writer): JsonResponse
    {
        return response()->json(['data' => $this->details($writer->setStatus($promotion, 'active'))]);
    }

    public function deactivate(SalesPromotion $promotion, SalesPromotionWriteService $writer): JsonResponse
    {
        return response()->json(['data' => $this->details($writer->setStatus($promotion, 'inactive'))]);
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
