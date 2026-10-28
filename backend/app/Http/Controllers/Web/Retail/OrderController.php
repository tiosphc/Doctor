<?php

namespace App\Http\Controllers\Web\Retail;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Services\OrderPaymentSummaryService;
use App\Services\SalesReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request, OrderPaymentSummaryService $payments): View
    {
        $filters = $request->validate([
            'order_status' => ['nullable', Rule::in(['pending', 'confirmed', 'preparing', 'shipping',
                'delivered', 'processing', 'completed', 'cancelled'])],
        ]);
        $orders = $payments->withSettledSum(SalesOrder::query())
            ->where('sales_channel', 'retail')->where('buyer_user_id', $request->user()->id)
            ->where('order_status', '!=', 'draft')
            ->when(filled($filters['order_status'] ?? null), fn ($query) => $query->where('order_status', $filters['order_status']))
            ->withCount('items')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('retail.orders.index', ['orders' => $orders, 'filters' => $filters]);
    }

    public function show(Request $request, int $order, OrderPaymentSummaryService $payments,
        SalesReturnService $returns): View
    {
        $record = SalesOrder::query()->whereKey($order)->where('sales_channel', 'retail')
            ->where('buyer_user_id', $request->user()->id)->where('order_status', '!=', 'draft')
            ->with(['items', 'warehouse:id,name,code', 'histories.actor:id,name',
                'promotionRedemptions.promotion:id,name', 'refunds' => fn ($query) => $query->where('status', 'completed')])
            ->firstOrFail();

        return view('retail.orders.show', [
            'order' => $record,
            'paymentSummary' => $payments->summary($record),
            'returnEligibility' => $returns->eligibility($record),
            'returnableItems' => $returns->returnable($record),
            'returnRequests' => $record->salesReturns()->with('items.salesOrderItem')->latest('id')->paginate(10),
            'returnChannel' => 'retail',
        ]);
    }
}
