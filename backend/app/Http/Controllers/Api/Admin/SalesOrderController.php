<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateSalesOrderRequest;
use App\Http\Requests\Admin\FulfillSalesOrderRequest;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\OrderPaymentSummaryService;
use App\Services\RegisteredCustomerService;
use App\Services\ReservationReconciliationService;
use App\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SalesOrderController extends Controller
{
    public function index(Request $request, OrderPaymentSummaryService $summaries): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'buyer_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'dealer_account_id' => ['nullable', 'integer', 'exists:dealer_accounts,id'],
            'sales_channel' => ['nullable', Rule::in(['retail', 'dealer'])],
            'order_source' => ['nullable', Rule::in(['admin', 'cart', 'quick_order', 'dealer_excel'])],
            'order_status' => ['nullable', Rule::in(['draft', 'pending', 'confirmed', 'preparing', 'shipping', 'delivered', 'processing', 'completed', 'cancelled'])],
            'payment_method' => ['nullable', Rule::in(['cod', 'bank_transfer'])],
            'payment_status' => ['nullable', Rule::in(['unpaid', 'pending', 'partially_paid', 'paid', 'partially_refunded', 'refunded'])],
            'fulfillment_status' => ['nullable', Rule::in(['unfulfilled', 'reserved', 'partially_fulfilled', 'fulfilled'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $summaries->withSettledSum(SalesOrder::query()
            ->with(['buyer:id,name,email', 'warehouse:id,code,name'])
            ->withCount(['items as items_count' => fn ($builder) => $builder->where('is_gift', false)])
            ->withSum(['items as total_quantity' => fn ($builder) => $builder->where('is_gift', false)], 'quantity'));
        foreach (['buyer_user_id', 'warehouse_id', 'dealer_account_id', 'sales_channel', 'order_source', 'order_status', 'payment_status', 'payment_method', 'fulfillment_status'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['search'])) {
            $query->where(fn ($builder) => $builder->where('order_code', 'like', '%'.$data['search'].'%')
                ->orWhere('recipient_name', 'like', '%'.$data['search'].'%')
                ->orWhere('recipient_phone', 'like', '%'.$data['search'].'%')
                ->orWhere('external_reference', 'like', '%'.$data['search'].'%')
                ->orWhere('dealer_code_snapshot', 'like', '%'.$data['search'].'%')
                ->orWhere('dealer_name_snapshot', 'like', '%'.$data['search'].'%'));
        }
        if (isset($data['date_from'])) {
            $query->whereDate('created_at', '>=', $data['date_from']);
        }
        if (isset($data['date_to'])) {
            $query->whereDate('created_at', '<=', $data['date_to']);
        }

        return response()->json($query->latest('id')->paginate($data['per_page'] ?? 20)
            ->through(fn (SalesOrder $order): SalesOrder => $summaries->attach($order)));
    }

    public function buyers(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $query = User::query()->where('role', User::ROLE_CUSTOMER)->select('id', 'name', 'email', 'phone');
        if (isset($data['search'])) {
            $query->where(fn ($builder) => $builder->where('name', 'like', '%'.$data['search'].'%')
                ->orWhere('email', 'like', '%'.$data['search'].'%')
                ->orWhere('phone', 'like', '%'.$data['search'].'%'));
        }

        return response()->json(['data' => $query->orderBy('name')->limit(50)->get()]);
    }

    public function buyer(User $buyer): JsonResponse
    {
        abort_unless($buyer->isCustomer(), 404);
        $address = SalesOrder::query()->where('buyer_user_id', $buyer->id)
            ->where('sales_channel', 'retail')->latest('id')
            ->first(['recipient_name', 'recipient_phone', 'recipient_email', 'shipping_address_line1',
                'shipping_address_line2', 'shipping_district', 'shipping_city', 'shipping_province',
                'shipping_country', 'shipping_postal_code', 'delivery_note']);

        return response()->json(['data' => [
            'id' => $buyer->id, 'name' => $buyer->name, 'email' => $buyer->email,
            'phone' => $buyer->phone, 'last_shipping' => $address,
        ]]);
    }

    public function createBuyer(Request $request, RegisteredCustomerService $customers): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'max:50'],
        ]);
        $buyer = DB::transaction(function () use ($data, $customers): User {
            $buyer = User::query()->create([
                ...$data, 'role' => User::ROLE_CUSTOMER, 'password' => Str::random(64),
            ]);
            $customers->ensureForUser($buyer);

            return $buyer;
        }, 3);

        return response()->json(['data' => $buyer->only(['id', 'name', 'email', 'phone'])], 201);
    }

    public function previewRetail(Request $request, SalesOrderService $service): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.sku' => ['required', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $service->previewRetail($data)]);
    }

    public function store(CreateSalesOrderRequest $request, SalesOrderService $service): JsonResponse
    {
        $data = $request->validated();
        $order = ($data['confirm'] ?? false)
            ? DB::transaction(function () use ($data, $request, $service): SalesOrder {
                $draft = $service->createDraft($data, $request->user()->id);

                return $service->confirm($draft, $data['confirm_operation_key'], $request->user()->id);
            }, 3)
            : $service->createDraft($data, $request->user()->id);

        return response()->json(['data' => $this->loaded($order)], 201);
    }

    public function show(SalesOrder $order): JsonResponse
    {
        return response()->json(['data' => $this->loaded($order)]);
    }

    public function updateDraft(CreateSalesOrderRequest $request, SalesOrder $order, SalesOrderService $service): JsonResponse
    {
        $data = $request->validated();
        $updated = DB::transaction(function () use ($data, $request, $order, $service): SalesOrder {
            $draft = $service->updateRetailDraft($order, $data, $request->user()->id);

            return ($data['confirm'] ?? false)
                ? $service->confirm($draft, $data['confirm_operation_key'], $request->user()->id)
                : $draft;
        }, 3);

        return response()->json(['data' => $this->loaded($updated)]);
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

    public function advance(Request $request, SalesOrder $order, SalesOrderService $service): JsonResponse
    {
        $data = $request->validate(['operation_key' => ['required', 'uuid'],
            'target' => ['required', Rule::in(['preparing', 'shipping', 'delivered', 'completed'])],
            'note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->loaded($service->retailAdvance($order, $data['operation_key'],
            $data['target'], $request->user()->id, $data['note'] ?? null))]);
    }

    public function warehouse(Request $request, SalesOrder $order, SalesOrderService $service): JsonResponse
    {
        $data = $request->validate(['operation_key' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id']]);

        return response()->json(['data' => $this->loaded($service->changeRetailWarehouse($order,
            $data['operation_key'], $data['warehouse_id'], $request->user()->id))]);
    }

    public function reconciliation(Request $request, ReservationReconciliationService $service): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id']]);

        return response()->json(['data' => $service->report($data['warehouse_id'] ?? null)]);
    }

    private function loaded(SalesOrder $order): SalesOrder
    {
        $order->refresh()->load(['buyer:id,name,email', 'warehouse:id,code,name,status',
            'dealerAccount:id,code,legal_name', 'items.reservation', 'reservations', 'histories.actor:id,name']);
        app(OrderPaymentSummaryService::class)->attach($order);

        $costs = DB::table('stock_movements as movement')
            ->join('sales_order_items as item', 'item.id', '=', 'movement.reference_id')
            ->where('item.sales_order_id', $order->id)
            ->where('movement.movement_type', 'SALES_ORDER_SHIPMENT')
            ->where('movement.reference_type', 'SALES_ORDER_ITEM')
            ->selectRaw('COUNT(*) as shipment_count, SUM(CASE WHEN movement.cost_amount IS NULL THEN 1 ELSE 0 END) as unknown_cost_count, SUM(movement.cost_amount) as cost_total')
            ->first();
        $costKnown = (int) $costs->shipment_count > 0 && (int) $costs->unknown_cost_count === 0;
        $costTotal = $costKnown ? bcadd((string) $costs->cost_total, '0', 2) : null;
        $order->setAttribute('cogs_total', $costTotal);
        $order->setAttribute('gross_profit', $costKnown && $order->fulfillment_status === 'fulfilled'
            && bccomp((string) $order->refunded_amount, '0', 2) === 0
            && ! $order->salesReturns()->exists()
            ? bcsub((string) $order->grand_total, $costTotal, 2) : null);

        return $order;
    }
}
