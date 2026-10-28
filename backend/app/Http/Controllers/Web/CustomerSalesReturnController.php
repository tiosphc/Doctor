<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestSalesReturnRequest;
use App\Models\DealerAccount;
use App\Models\SalesOrder;
use App\Services\DealerContextService;
use App\Services\DealerTierService;
use App\Services\SalesReturnService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerSalesReturnController extends Controller
{
    public function retailCreate(Request $request, int $order, SalesReturnService $returns): View
    {
        return $this->createView($this->retailOrder($request, $order), $returns, 'retail');
    }

    public function retailStore(RequestSalesReturnRequest $request, int $order, SalesReturnService $returns): RedirectResponse
    {
        return $this->store($request, $this->retailOrder($request, $order), $returns, 'retail');
    }

    public function retailShow(Request $request, int $order, int $salesReturn): View
    {
        return $this->showView($this->retailOrder($request, $order), $salesReturn, 'retail');
    }

    public function dealerCreate(Request $request, int $order, DealerContextService $context,
        DealerTierService $tiers, SalesReturnService $returns): View
    {
        [$account, $record] = $this->dealerOrder($request, $order, $context);

        return $this->createView($record, $returns, 'dealer', $this->dealerLayout($account, $tiers));
    }

    public function dealerStore(RequestSalesReturnRequest $request, int $order,
        DealerContextService $context, SalesReturnService $returns): RedirectResponse
    {
        [, $record] = $this->dealerOrder($request, $order, $context);

        return $this->store($request, $record, $returns, 'dealer');
    }

    public function dealerShow(Request $request, int $order, int $salesReturn,
        DealerContextService $context, DealerTierService $tiers): View
    {
        [$account, $record] = $this->dealerOrder($request, $order, $context);

        return $this->showView($record, $salesReturn, 'dealer', $this->dealerLayout($account, $tiers));
    }

    private function retailOrder(Request $request, int $order): SalesOrder
    {
        return SalesOrder::query()->whereKey($order)->where('sales_channel', 'retail')
            ->where('buyer_user_id', $request->user()->id)->where('order_status', '!=', 'draft')->firstOrFail();
    }

    /** @return array{DealerAccount, SalesOrder} */
    private function dealerOrder(Request $request, int $order, DealerContextService $context): array
    {
        $account = $context->primaryAccount($request->user());
        abort_if($account === null, 403, 'Cần tư cách đại lý đang hoạt động.');
        $record = SalesOrder::query()->whereKey($order)->where('sales_channel', 'dealer')
            ->where('dealer_account_id', $account->id)->where('order_status', '!=', 'draft')->firstOrFail();

        return [$account, $record];
    }

    /** @param array<string, mixed> $layout */
    private function createView(SalesOrder $order, SalesReturnService $returns, string $channel,
        array $layout = []): View
    {
        return view($channel === 'retail' ? 'retail.returns.create' : 'dealer.returns.create', [
            'order' => $order,
            'eligibility' => $returns->eligibility($order),
            'returnableItems' => $returns->returnable($order),
            'operationKey' => old('operation_key', (string) Str::uuid()),
            'channel' => $channel,
            ...$layout,
        ]);
    }

    /** @param array<string, mixed> $layout */
    private function showView(SalesOrder $order, int $salesReturn, string $channel, array $layout = []): View
    {
        $entry = $order->salesReturns()->whereKey($salesReturn)
            ->with(['items.salesOrderItem', 'refunds' => fn ($query) => $query->where('status', 'completed')])
            ->firstOrFail();

        return view($channel === 'retail' ? 'retail.returns.show' : 'dealer.returns.show', [
            'order' => $order,
            'returnEntry' => $entry,
            'channel' => $channel,
            ...$layout,
        ]);
    }

    private function store(RequestSalesReturnRequest $request, SalesOrder $order,
        SalesReturnService $returns, string $channel): RedirectResponse
    {
        $data = $request->validated();
        $data['reason'] = $data['reason_code'];
        $data['processing_mode'] = 'customer_request';
        $data['request_source'] = $channel;

        try {
            $entry = $returns->complete($order, $data, $request->user()->id);
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $code = $exception->getResponse()->getData(true)['code'] ?? 'RETURN_CONFLICT';
            throw ValidationException::withMessages(['return' => 'Không thể gửi yêu cầu trả hàng ('.$code.'). Vui lòng kiểm tra lại đơn hàng và số lượng có thể trả.']);
        }

        return redirect()->route($channel === 'retail' ? 'retail.web.orders.returns.show' : 'dealer.web.orders.returns.show',
            [$order->id, $entry->id])->with('success', 'Đã gửi yêu cầu trả hàng. Nhân viên sẽ xem xét trước khi nhận hàng hoặc hoàn tiền.');
    }

    /** @return array<string, mixed> */
    private function dealerLayout(DealerAccount $account, DealerTierService $tiers): array
    {
        $account->load('wallet');

        return ['account' => $account, 'effectiveTier' => $tiers->resolve($account)['effective_tier']];
    }
}
