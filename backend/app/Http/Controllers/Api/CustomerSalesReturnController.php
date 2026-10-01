<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestSalesReturnRequest;
use App\Models\DealerAccount;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Services\DealerContextService;
use App\Services\SalesReturnService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerSalesReturnController extends Controller
{
    public function retailEligibility(Request $request, int $order, SalesReturnService $service): JsonResponse
    {
        return $this->eligibility($service, $this->retailOrder($request, $order));
    }

    public function retailIndex(Request $request, int $order): JsonResponse
    {
        return $this->index($this->retailOrder($request, $order));
    }

    public function retailStore(RequestSalesReturnRequest $request, int $order, SalesReturnService $service): JsonResponse
    {
        return $this->store($request, $this->retailOrder($request, $order), $service, 'retail');
    }

    public function retailShow(Request $request, SalesReturn $salesReturn): JsonResponse
    {
        $this->retailOrder($request, $salesReturn->sales_order_id);

        return response()->json(['data' => $this->item($salesReturn)]);
    }

    public function dealerEligibility(Request $request, DealerAccount $dealer, int $order,
        DealerContextService $context, SalesReturnService $service): JsonResponse
    {
        return $this->eligibility($service, $this->dealerOrder($request, $dealer, $order, $context));
    }

    public function dealerIndex(Request $request, DealerAccount $dealer, int $order, DealerContextService $context): JsonResponse
    {
        return $this->index($this->dealerOrder($request, $dealer, $order, $context));
    }

    public function dealerStore(RequestSalesReturnRequest $request, DealerAccount $dealer, int $order,
        DealerContextService $context, SalesReturnService $service): JsonResponse
    {
        return $this->store($request, $this->dealerOrder($request, $dealer, $order, $context), $service, 'dealer');
    }

    public function dealerShow(Request $request, DealerAccount $dealer, SalesReturn $salesReturn,
        DealerContextService $context): JsonResponse
    {
        $this->dealerOrder($request, $dealer, $salesReturn->sales_order_id, $context);

        return response()->json(['data' => $this->item($salesReturn)]);
    }

    private function retailOrder(Request $request, int $order): SalesOrder
    {
        $record = SalesOrder::query()->findOrFail($order);
        abort_unless($record->sales_channel === 'retail' && $record->buyer_user_id === $request->user()->id, 403);

        return $record;
    }

    private function dealerOrder(Request $request, DealerAccount $dealer, int $order, DealerContextService $context): SalesOrder
    {
        try {
            $context->resolve($request->user(), $dealer);
        } catch (ModelNotFoundException) {
            abort(403);
        }
        $record = SalesOrder::query()->findOrFail($order);
        abort_unless($record->sales_channel === 'dealer' && $record->dealer_account_id === $dealer->id, 403);

        return $record;
    }

    private function eligibility(SalesReturnService $service, SalesOrder $order): JsonResponse
    {
        return response()->json(['data' => [
            ...$service->eligibility($order),
            'items' => $service->returnable($order),
        ]]);
    }

    private function index(SalesOrder $order): JsonResponse
    {
        return response()->json(['data' => $order->salesReturns()->with(['items.salesOrderItem', 'requestedBy', 'refunds'])
            ->latest('id')->get()->map(fn (SalesReturn $entry): array => $this->item($entry))]);
    }

    private function store(RequestSalesReturnRequest $request, SalesOrder $order, SalesReturnService $service, string $source): JsonResponse
    {
        $data = $request->validated();
        $data['reason'] = $data['reason_code'];
        $data['processing_mode'] = 'customer_request';
        $data['request_source'] = $source;
        $entry = $service->complete($order, $data, $request->user()->id);

        return response()->json(['data' => $this->item($entry)], 201);
    }

    /** @return array<string, mixed> */
    private function item(SalesReturn $salesReturn): array
    {
        $salesReturn->loadMissing(['items.salesOrderItem', 'requestedBy', 'refunds']);

        return [
            'id' => $salesReturn->id,
            'return_code' => $salesReturn->return_code,
            'sales_order_id' => $salesReturn->sales_order_id,
            'status' => $salesReturn->status,
            'request_source' => $salesReturn->request_source ?? 'admin',
            'requested_by' => $salesReturn->requestedBy?->only(['id', 'name']),
            'reason_code' => $salesReturn->reason_code,
            'reason' => $salesReturn->reason,
            'note' => $salesReturn->note,
            'rejection_reason' => $salesReturn->rejection_reason,
            'requested_at' => $salesReturn->created_at,
            'approved_at' => $salesReturn->approved_at,
            'received_at' => $salesReturn->received_at,
            'completed_at' => $salesReturn->completed_at,
            'rejected_at' => $salesReturn->rejected_at,
            'refunded_amount' => $salesReturn->refunds->where('status', 'completed')->sum('amount'),
            'refunded_at' => $salesReturn->refunds->where('status', 'completed')->sortByDesc('completed_at')->first()?->completed_at,
            'items' => $salesReturn->items->map(fn ($item): array => [
                'id' => $item->id,
                'sales_order_item_id' => $item->sales_order_item_id,
                'product_name' => $item->salesOrderItem?->product_name_snapshot,
                'sku' => $item->salesOrderItem?->sku_snapshot,
                'variant_name' => $item->salesOrderItem?->variant_name_snapshot,
                'quantity' => $item->quantity,
                'restock_quantity' => $salesReturn->status === 'completed' ? $item->restock_quantity : null,
                'non_restock_quantity' => $salesReturn->status === 'completed' ? $item->non_restock_quantity : null,
            ]),
        ];
    }
}
