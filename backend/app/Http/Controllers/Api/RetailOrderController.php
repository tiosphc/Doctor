<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RetailSalesOrderResource;
use App\Models\SalesOrder;
use App\Services\OrderPaymentSummaryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class RetailOrderController extends Controller
{
    public function index(Request $request, OrderPaymentSummaryService $summaries): AnonymousResourceCollection
    {
        $data = $request->validate([
            'order_status' => ['nullable', Rule::in(['pending', 'confirmed', 'preparing', 'shipping', 'delivered', 'processing', 'completed', 'cancelled'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = $summaries->withSettledSum(SalesOrder::query())->where('sales_channel', 'retail')
            ->where('buyer_user_id', $request->user()->id)
            ->where('order_status', '!=', 'draft')
            ->with(['items', 'promotionRedemptions.promotion:id,name', 'refunds' => fn ($query) => $query->where('status', 'completed')]);
        if (isset($data['order_status'])) {
            $query->where('order_status', $data['order_status']);
        }

        return RetailSalesOrderResource::collection($query->orderByDesc('id')->paginate($data['per_page'] ?? 20));
    }

    public function show(Request $request, int $order, OrderPaymentSummaryService $summaries): RetailSalesOrderResource
    {
        $record = $summaries->withSettledSum(SalesOrder::query())->whereKey($order)->where('sales_channel', 'retail')
            ->where('buyer_user_id', $request->user()->id)
            ->where('order_status', '!=', 'draft')
            ->with(['items', 'promotionRedemptions.promotion:id,name', 'histories.actor:id,name', 'refunds' => fn ($query) => $query->where('status', 'completed'),
                'salesReturns.items'])->firstOrFail();

        return new RetailSalesOrderResource($record);
    }
}
