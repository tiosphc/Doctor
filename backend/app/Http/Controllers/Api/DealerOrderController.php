<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DealerSalesOrderResource;
use App\Models\DealerAccount;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DealerContextService;
use App\Services\DealerOrderCancellationService;
use App\Services\OrderPaymentSummaryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DealerOrderController extends Controller
{
    public function index(Request $request, DealerAccount $dealer, DealerContextService $context,
        OrderPaymentSummaryService $summaries): AnonymousResourceCollection
    {
        abort_unless($request->user()->role === User::ROLE_CUSTOMER, 404);
        $context->resolve($request->user(), $dealer);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'order_status' => ['nullable', Rule::in(['pending', 'confirmed', 'preparing', 'shipping', 'processing', 'delivered', 'completed', 'cancelled'])],
            'status_group' => ['nullable', Rule::in(['pending', 'active', 'completed', 'cancelled'])],
            'payment_status' => ['nullable', Rule::in(['unpaid', 'pending', 'partially_paid', 'paid', 'failed', 'partially_refunded', 'refunded'])],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'order_source' => ['nullable', Rule::in(['quick_order', 'dealer_excel'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $base = SalesOrder::query()->where('sales_channel', 'dealer')
            ->where('dealer_account_id', $dealer->id)
            ->whereIn('order_status', ['pending', 'confirmed', 'preparing', 'shipping', 'processing', 'delivered', 'completed', 'cancelled']);
        foreach (['payment_status', 'warehouse_id', 'order_source'] as $field) {
            if (isset($data[$field])) {
                $base->where($field, $data[$field]);
            }
        }
        if (isset($data['search'])) {
            $search = trim($data['search']);
            if ($search !== '') {
                $base->where(fn ($query) => $query->where('order_code', 'like', '%'.$search.'%')
                    ->orWhere('external_reference', 'like', '%'.$search.'%')
                    ->orWhere('recipient_name', 'like', '%'.$search.'%')
                    ->orWhere('recipient_phone', 'like', '%'.$search.'%'));
            }
        }
        if (isset($data['date_from'])) {
            $base->whereDate('created_at', '>=', $data['date_from']);
        }
        if (isset($data['date_to'])) {
            $base->whereDate('created_at', '<=', $data['date_to']);
        }
        $statusCounts = (clone $base)->select('order_status')->selectRaw('COUNT(*) as total')
            ->groupBy('order_status')->pluck('total', 'order_status');
        $query = $summaries->withSettledSum($base)->with(['buyer:id,name', 'warehouse:id,code,name', 'promotionRedemptions.promotion:id,name',
            'refunds' => fn ($query) => $query->where('status', 'completed')])
            ->withCount(['items as items_count' => fn ($query) => $query->where('is_gift', false)])
            ->withSum(['items as total_quantity' => fn ($query) => $query->where('is_gift', false)], 'quantity');
        if (isset($data['order_status'])) {
            $query->where('order_status', $data['order_status']);
        }
        if (isset($data['status_group'])) {
            $query->whereIn('order_status', $data['status_group'] === 'active'
                ? ['confirmed', 'preparing', 'shipping', 'processing', 'delivered'] : [$data['status_group']]);
        }

        $warehouses = Warehouse::query()->whereIn('id', SalesOrder::query()->where('sales_channel', 'dealer')
            ->where('dealer_account_id', $dealer->id)->select('warehouse_id'))
            ->orderBy('name')->get(['id', 'code', 'name']);

        return DealerSalesOrderResource::collection($query->orderByDesc('id')->paginate($data['per_page'] ?? 20))
            ->additional(['status_counts' => ['all' => $statusCounts->sum(), 'active' => (int) $statusCounts->only(['confirmed', 'preparing', 'shipping', 'processing', 'delivered'])->sum(), ...$statusCounts->all()],
                'warehouses' => $warehouses]);
    }

    public function show(Request $request, DealerAccount $dealer, int $order, DealerContextService $context,
        OrderPaymentSummaryService $summaries): DealerSalesOrderResource
    {
        abort_unless($request->user()->role === User::ROLE_CUSTOMER, 404);
        $context->resolve($request->user(), $dealer);
        $record = $summaries->withSettledSum(SalesOrder::query())->whereKey($order)->where('sales_channel', 'dealer')
            ->where('dealer_account_id', $dealer->id)
            ->whereIn('order_status', ['pending', 'confirmed', 'preparing', 'shipping', 'processing', 'delivered', 'completed', 'cancelled'])
            ->with(['buyer:id,name', 'warehouse:id,code,name', 'items.reservation', 'promotionRedemptions.promotion:id,name',
                'refunds' => fn ($query) => $query->where('status', 'completed'),
                'salesReturns.items'])->firstOrFail();

        return new DealerSalesOrderResource($record);
    }

    public function cancel(Request $request, DealerAccount $dealer, int $order, DealerContextService $context,
        DealerOrderCancellationService $cancellations): DealerSalesOrderResource
    {
        abort_unless($request->user()->role === User::ROLE_CUSTOMER
            && $context->primaryAccount($request->user())?->id === $dealer->id, 404);
        $data = $request->validate(['operation_key' => ['required', 'uuid']]);
        $record = $cancellations->cancel($dealer, $order, $data['operation_key'], $request->user());

        return new DealerSalesOrderResource($record->load(['buyer:id,name', 'warehouse:id,code,name', 'items.reservation', 'promotionRedemptions.promotion:id,name',
            'refunds' => fn ($query) => $query->where('status', 'completed'), 'salesReturns.items']));
    }
}
