<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateSalesOrderRequest;
use App\Http\Requests\Admin\FulfillSalesOrderRequest;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\ReservationReconciliationService;
use App\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'buyer_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'order_status' => ['nullable', Rule::in(['draft', 'confirmed', 'processing', 'completed', 'cancelled'])],
            'payment_status' => ['nullable', Rule::in(['unpaid', 'pending', 'partially_paid', 'paid', 'partially_refunded', 'refunded'])],
            'fulfillment_status' => ['nullable', Rule::in(['unfulfilled', 'reserved', 'partially_fulfilled', 'fulfilled'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = SalesOrder::query()->with(['buyer:id,name,email', 'warehouse:id,code,name'])->withCount('items');
        foreach (['buyer_user_id', 'warehouse_id', 'order_status', 'payment_status', 'fulfillment_status'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['search'])) {
            $query->where(fn ($builder) => $builder->where('order_code', 'like', '%'.$data['search'].'%')
                ->orWhere('recipient_name', 'like', '%'.$data['search'].'%'));
        }
        if (isset($data['date_from'])) {
            $query->whereDate('created_at', '>=', $data['date_from']);
        }
        if (isset($data['date_to'])) {
            $query->whereDate('created_at', '<=', $data['date_to']);
        }

        return response()->json($query->latest('id')->paginate($data['per_page'] ?? 20));
    }

    public function buyers(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $query = User::query()->where('role', User::ROLE_CUSTOMER)->select('id', 'name', 'email', 'phone');
        if (isset($data['search'])) {
            $query->where(fn ($builder) => $builder->where('name', 'like', '%'.$data['search'].'%')
                ->orWhere('email', 'like', '%'.$data['search'].'%'));
        }

        return response()->json(['data' => $query->orderBy('name')->limit(50)->get()]);
    }

    public function store(CreateSalesOrderRequest $request, SalesOrderService $service): JsonResponse
    {
        $order = $service->createDraft($request->validated(), $request->user()->id);

        return response()->json(['data' => $this->loaded($order)], 201);
    }

    public function show(SalesOrder $order): JsonResponse
    {
        return response()->json(['data' => $this->loaded($order)]);
    }

    public function reprice(Request $request, SalesOrder $order, SalesOrderService $service): JsonResponse
    {
        $data = $request->validate(['operation_key' => ['required', 'uuid']]);

        return response()->json(['data' => $this->loaded($service->repriceDraft($order, $data['operation_key'], $request->user()->id))]);
    }

    public function confirm(Request $request, SalesOrder $order, SalesOrderService $service): JsonResponse
    {
        $data = $request->validate(['operation_key' => ['required', 'uuid']]);

        return response()->json(['data' => $this->loaded($service->confirm($order, $data['operation_key'], $request->user()->id))]);
    }

    public function cancel(Request $request, SalesOrder $order, SalesOrderService $service): JsonResponse
    {
        $data = $request->validate(['operation_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $this->loaded($service->cancel($order, $data['operation_key'], $data['reason'], $request->user()->id))]);
    }

    public function fulfill(FulfillSalesOrderRequest $request, SalesOrder $order, SalesOrderService $service): JsonResponse
    {
        $data = $request->validated();
        $quantities = [];
        foreach ($data['items'] as $item) {
            $quantities[(int) $item['item_id']] = (string) $item['quantity'];
        }

        return response()->json(['data' => $this->loaded($service->fulfill($order, $data['operation_key'], $quantities, $request->user()->id))]);
    }

    public function reconciliation(Request $request, ReservationReconciliationService $service): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id']]);

        return response()->json(['data' => $service->report($data['warehouse_id'] ?? null)]);
    }

    private function loaded(SalesOrder $order): SalesOrder
    {
        return $order->refresh()->load(['buyer:id,name,email', 'warehouse:id,code,name,status',
            'items.reservation', 'reservations']);
    }
}
