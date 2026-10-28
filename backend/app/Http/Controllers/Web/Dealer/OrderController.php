<?php

namespace App\Http\Controllers\Web\Dealer;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewDealerQuickOrderRequest;
use App\Http\Requests\SubmitDealerQuickOrderRequest;
use App\Models\AdministrativeProvince;
use App\Models\DealerAccount;
use App\Models\DealerShippingAddress;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\DealerContextService;
use App\Services\DealerEffectivePricingService;
use App\Services\DealerOrderCancellationService;
use App\Services\DealerQuickOrderService;
use App\Services\DealerTierService;
use App\Services\OrderPaymentSummaryService;
use App\Services\SalesReturnService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request, DealerContextService $context, DealerTierService $tiers,
        OrderPaymentSummaryService $summaries): View
    {
        $account = $this->account($request, $context);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'order_status' => ['nullable', Rule::in(['pending', 'confirmed', 'preparing', 'shipping', 'processing', 'delivered', 'completed', 'cancelled'])],
            'payment_status' => ['nullable', Rule::in(['unpaid', 'pending', 'partially_paid', 'paid', 'failed', 'partially_refunded', 'refunded'])],
            'order_source' => ['nullable', Rule::in(['quick_order', 'dealer_excel'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $query = $summaries->withSettledSum(SalesOrder::query())
            ->where('sales_channel', 'dealer')->where('dealer_account_id', $account->id)
            ->whereIn('order_status', ['pending', 'confirmed', 'preparing', 'shipping', 'processing', 'delivered', 'completed', 'cancelled'])
            ->with(['warehouse:id,code,name', 'buyer:id,name'])
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $search = trim($filters['search']);
                $query->where(fn ($query) => $query->where('order_code', 'like', "%{$search}%")
                    ->orWhere('external_reference', 'like', "%{$search}%")
                    ->orWhere('recipient_name', 'like', "%{$search}%")
                    ->orWhere('recipient_phone', 'like', "%{$search}%"));
            });
        foreach (['order_status', 'payment_status', 'order_source'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return view('dealer.orders.index', [
            'orders' => $query->latest('id')->paginate(20)->withQueryString(),
            'filters' => $filters, ...$this->layout($account, $tiers),
        ]);
    }

    public function show(Request $request, int $order, DealerContextService $context, DealerTierService $tiers,
        OrderPaymentSummaryService $summaries, SalesReturnService $returns): View
    {
        $account = $this->account($request, $context);
        $record = SalesOrder::query()->whereKey($order)->where('sales_channel', 'dealer')
            ->where('dealer_account_id', $account->id)
            ->whereIn('order_status', ['pending', 'confirmed', 'preparing', 'shipping', 'processing', 'delivered', 'completed', 'cancelled'])
            ->with(['buyer:id,name', 'warehouse:id,code,name', 'items.reservation', 'histories.actor:id,name',
                'paymentAllocations.payment', 'refunds'])->firstOrFail();

        return view('dealer.orders.show', [
            'order' => $record, 'paymentSummary' => $summaries->summary($record),
            'cancelKey' => old('operation_key', (string) Str::uuid()),
            'returnEligibility' => $returns->eligibility($record),
            'returnableItems' => $returns->returnable($record),
            'returnRequests' => $record->salesReturns()->with('items.salesOrderItem')->latest('id')->paginate(10),
            'returnChannel' => 'dealer',
            ...$this->layout($account, $tiers),
        ]);
    }

    public function cancel(Request $request, int $order, DealerContextService $context,
        DealerOrderCancellationService $cancellations): RedirectResponse
    {
        $account = $this->account($request, $context);
        $data = $request->validate(['operation_key' => ['required', 'uuid']]);
        $this->runDomain(fn () => $cancellations->cancel($account, $order, $data['operation_key'], $request->user()));

        return redirect()->route('dealer.web.orders.show', $order)->with('success', 'Đã xử lý yêu cầu hủy đơn.');
    }

    public function quickCreate(Request $request, DealerContextService $context, DealerTierService $tiers,
        DealerEffectivePricingService $pricing): View
    {
        $account = $this->account($request, $context);
        $items = collect(old('items', []))->filter(fn ($item): bool => is_array($item) && isset($item['product_variant_id']))->take(50);
        $variants = ProductVariant::query()->with('product:id,name')->whereIn('id', $items->pluck('product_variant_id'))->get()->keyBy('id');
        $initialItems = $items->map(function (array $item) use ($variants): array {
            $variant = $variants->get((int) $item['product_variant_id']);

            return ['product_variant_id' => (int) $item['product_variant_id'],
                'quantity' => (string) ($item['quantity'] ?? '1'),
                'label' => $variant === null ? (string) $item['product_variant_id']
                    : $variant->product?->name.' · '.$variant->variant_name.' · '.$variant->sku];
        })->values()->all();
        $preselectionError = null;
        $requestedVariantId = $request->query('variant_id');
        if ($items->isEmpty() && $requestedVariantId !== null) {
            $variantId = is_string($requestedVariantId) && ctype_digit($requestedVariantId)
                ? filter_var($requestedVariantId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            $variant = $variantId === false ? null : $this->quickSkuQuery()
                ->with('product:id,name,status,gift_only')->find($variantId);
            $price = $variant === null ? null : ($pricing->catalogPrices(
                $pricing->context($request->user(), $account), collect([$variant]),
            )[$variant->id] ?? null);
            if ($variant === null || $price === null) {
                $preselectionError = 'SKU này hiện không thể đặt qua Đặt hàng nhanh. Hãy chọn SKU khác trong danh mục.';
            } else {
                $initialItems[] = [
                    'product_variant_id' => $variant->id,
                    'quantity' => (string) max(1, (int) ceil((float) $price['minimum_quantity'])),
                    'label' => $variant->product->name.' · '.$variant->variant_name.' · '.$variant->sku,
                    'unit_price' => $price['unit_price'],
                    'minimum_quantity' => $price['minimum_quantity'],
                ];
            }
        }
        $addresses = DealerShippingAddress::query()->where('dealer_account_id', $account->id)
            ->with(['province', 'ward'])->orderByDesc('is_default')->orderBy('id')->get();

        return view('dealer.orders.quick', [
            'addresses' => $addresses,
            'provinces' => AdministrativeProvince::query()->orderBy('name')->get(['code', 'name']),
            'initialItems' => $initialItems,
            'preselectionError' => $preselectionError,
            'review' => session('review'),
            'operationKey' => old('operation_key', (string) Str::uuid()),
            ...$this->layout($account, $tiers),
        ]);
    }

    public function quickOptions(Request $request, DealerContextService $context,
        DealerEffectivePricingService $pricing): JsonResponse
    {
        $account = $this->account($request, $context);
        $data = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);
        $search = trim($data['search']);
        $variants = $this->quickSkuQuery()->with('product:id,name,status,gift_only')
            ->where(fn ($query) => $query->where('sku', 'like', "%{$search}%")
                ->orWhere('variant_name', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%")))
            ->orderBy('sku')->limit(20)->get();
        $prices = $pricing->catalogPrices($pricing->context($request->user(), $account), $variants);

        return response()->json(['data' => $variants->filter(fn (ProductVariant $variant): bool => isset($prices[$variant->id]))
            ->map(fn (ProductVariant $variant): array => [
                'id' => $variant->id, 'sku' => $variant->sku,
                'label' => $variant->product?->name.' · '.$variant->variant_name.' · '.$variant->sku,
                'unit_price' => $prices[$variant->id]['unit_price'],
                'minimum_quantity' => $prices[$variant->id]['minimum_quantity'],
            ])->values()->all()]);
    }

    private function quickSkuQuery(): Builder
    {
        return ProductVariant::query()->where('status', 'active')->where('sellable_dealer', true)
            ->where('track_inventory', true)
            ->whereHas('product', fn (Builder $query) => $query->where('status', 'active')
                ->where('gift_only', false));
    }

    public function quickReview(ReviewDealerQuickOrderRequest $request, DealerContextService $context,
        DealerQuickOrderService $orders): RedirectResponse
    {
        $account = $this->account($request, $context);
        $data = $request->validated();
        $review = $this->runDomain(fn () => $orders->review($request->user(), $account, $data['items'], $data));

        return redirect()->route('dealer.web.quick.create')
            ->withInput($request->except('_token'))->with('review', $review);
    }

    public function quickStore(SubmitDealerQuickOrderRequest $request, DealerContextService $context,
        DealerQuickOrderService $orders): RedirectResponse
    {
        $account = $this->account($request, $context);
        $order = $this->runDomain(fn () => $orders->submit($request->user(), $account, $request->validated()));

        return redirect()->route('dealer.web.orders.show', $order->id)
            ->with('success', 'Đã tạo đơn đại lý '.$order->order_code.'.');
    }

    private function account(Request $request, DealerContextService $context): DealerAccount
    {
        abort_unless($request->user()?->role === User::ROLE_CUSTOMER, 404);
        $account = $context->primaryAccount($request->user());
        abort_if($account === null, 403, 'Cần tư cách đại lý đang hoạt động.');

        return $account;
    }

    /** @return array<string, mixed> */
    private function layout(DealerAccount $account, DealerTierService $tiers): array
    {
        $account->load('wallet');

        return ['account' => $account, 'effectiveTier' => $tiers->resolve($account)['effective_tier']];
    }

    private function runDomain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $code = $exception->getResponse()->getData(true)['code'] ?? 'DEALER_ORDER_CONFLICT';
            throw ValidationException::withMessages(['operation' => 'Không thể xử lý ('.$code.'). Hãy xem trước lại dữ liệu hiện tại.']);
        }
    }
}
