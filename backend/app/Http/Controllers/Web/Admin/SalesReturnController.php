<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompleteRefundRequest;
use App\Http\Requests\Admin\CompleteSalesReturnRequest;
use App\Http\Requests\Admin\ProcessSalesReturnRequest;
use App\Models\AuditLog;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Services\OrderPaymentSummaryService;
use App\Services\RefundService;
use App\Services\SalesReturnService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesReturnController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['requested', 'approved', 'rejected', 'pending', 'completed'])],
            'sales_channel' => ['nullable', Rule::in(['retail', 'dealer'])],
            'request_source' => ['nullable', Rule::in(['admin', 'retail', 'dealer'])],
        ]);
        $returns = SalesReturn::query()
            ->with(['salesOrder:id,order_code,sales_channel,buyer_user_id,dealer_name_snapshot,recipient_name', 'salesOrder.buyer:id,name', 'requestedBy:id,name', 'approvedBy:id,name'])
            ->withSum('items as requested_quantity', 'quantity')
            ->withSum('items as return_value', 'return_value_snapshot')
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $search = $filters['search'];
                $query->where(function ($query) use ($search): void {
                    $query->where('return_code', 'like', "%{$search}%")
                        ->orWhereHas('salesOrder', fn ($order) => $order->where('order_code', 'like', "%{$search}%")
                            ->orWhere('recipient_name', 'like', "%{$search}%"));
                });
            })
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['request_source'] ?? null), fn ($query) => $query->where('request_source', $filters['request_source']))
            ->when(filled($filters['sales_channel'] ?? null), fn ($query) => $query->whereHas('salesOrder', fn ($order) => $order->where('sales_channel', $filters['sales_channel'])))
            ->latest('id')->paginate(20)->withQueryString();

        return view('admin.sales-returns.index', compact('returns', 'filters'));
    }

    public function create(SalesOrder $order, SalesReturnService $returns): View
    {
        return view('admin.sales-returns.create', [
            'order' => $order->load(['buyer:id,name', 'warehouse:id,code,name']),
            'returnableItems' => collect($returns->returnable($order))->filter(fn (array $item): bool => bccomp($item['returnable_quantity'], '0', 3) > 0),
            'operationKey' => old('operation_key', (string) Str::uuid()),
        ]);
    }

    public function store(CompleteSalesReturnRequest $request, SalesOrder $order, SalesReturnService $returns): RedirectResponse
    {
        $data = $request->validated();
        $data['processing_mode'] = 'pending_inspection';
        $salesReturn = $this->runDomain(fn () => $returns->complete($order, $data, $request->user()->id));

        return redirect()->route('admin.web.sales.returns.show', $salesReturn)->with('success', 'Đã ghi nhận phiếu trả hàng.');
    }

    public function show(SalesReturn $salesReturn, OrderPaymentSummaryService $summaries): View
    {
        $salesReturn->load(['salesOrder.buyer', 'salesOrder.warehouse', 'salesOrder.paymentAllocations.payment', 'items.salesOrderItem', 'requestedBy', 'approvedBy', 'rejectedBy', 'receivedBy', 'processedBy', 'refunds.allocations.paymentAllocation.payment']);
        $order = $salesReturn->salesOrder;
        $history = AuditLog::query()->where(function ($query) use ($salesReturn): void {
            $query->where('target_type', 'SalesReturn')->where('target_id', $salesReturn->id);
        })->orWhere(function ($query) use ($salesReturn): void {
            $query->where('target_type', 'Refund')->whereIn('target_id', $salesReturn->refunds->pluck('id'));
        })->oldest('id')->get();

        return view('admin.sales-returns.show', [
            'salesReturn' => $salesReturn,
            'order' => $order,
            'paymentSummary' => $summaries->summary($order),
            'history' => $history,
            'operationKey' => old('operation_key', (string) Str::uuid()),
            'refundKey' => old('operation_key', (string) Str::uuid()),
        ]);
    }

    public function approve(Request $request, SalesReturn $salesReturn, SalesReturnService $returns): RedirectResponse
    {
        $this->runDomain(fn () => $returns->approve($salesReturn, $request->user()->id));

        return $this->done($salesReturn, 'Đã duyệt yêu cầu trả hàng.');
    }

    public function reject(Request $request, SalesReturn $salesReturn, SalesReturnService $returns): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->runDomain(fn () => $returns->reject($salesReturn, $data['reason'], $request->user()->id));

        return $this->done($salesReturn, 'Đã từ chối yêu cầu trả hàng.');
    }

    public function receive(Request $request, SalesReturn $salesReturn, SalesReturnService $returns): RedirectResponse
    {
        $this->runDomain(fn () => $returns->receive($salesReturn, $request->user()->id));

        return $this->done($salesReturn, 'Đã ghi nhận nhận hàng trả.');
    }

    public function process(ProcessSalesReturnRequest $request, SalesReturn $salesReturn, SalesReturnService $returns): RedirectResponse
    {
        $this->runDomain(fn () => $returns->process($salesReturn, $request->validated(), $request->user()->id));

        return $this->done($salesReturn, 'Đã hoàn tất kiểm hàng trả.');
    }

    public function refund(CompleteRefundRequest $request, SalesReturn $salesReturn, RefundService $refunds): RedirectResponse
    {
        $data = $request->validated();
        $data['reason'] = 'return';
        $data['return_id'] = $salesReturn->id;
        $this->runDomain(fn () => $refunds->complete($salesReturn->salesOrder, $data, $request->user()->id));

        return $this->done($salesReturn, 'Đã ghi nhận khoản hoàn tiền.');
    }

    private function done(SalesReturn $salesReturn, string $message): RedirectResponse
    {
        return redirect()->route('admin.web.sales.returns.show', $salesReturn)->with('success', $message);
    }

    private function runDomain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $code = $exception->getResponse()->getData(true)['code'] ?? 'RETURN_REFUND_CONFLICT';
            throw ValidationException::withMessages(['operation' => 'Thao tác không còn hợp lệ ('.$code.'). Hãy tải lại trang và kiểm tra dữ liệu hiện tại.']);
        }
    }
}
