<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateBladeSalesOrderRequest;
use App\Http\Requests\Admin\FulfillSalesOrderRequest;
use App\Http\Requests\Admin\RecordPaymentRequest;
use App\Models\PaymentAllocation;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AdminSalesOrderReadService;
use App\Services\InventoryReservationService;
use App\Services\OrderPaymentSummaryService;
use App\Services\PaymentService;
use App\Services\SalesOrderService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesOrderController extends Controller
{
    public function index(Request $request, AdminSalesOrderReadService $orders): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'sales_channel' => ['nullable', Rule::in(['retail', 'dealer'])],
            'order_status' => ['nullable', Rule::in(['draft', 'pending', 'confirmed', 'preparing', 'shipping', 'delivered', 'processing', 'completed', 'cancelled'])],
            'payment_status' => ['nullable', Rule::in(['unpaid', 'pending', 'partially_paid', 'paid', 'partially_refunded', 'refunded'])],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        return view('admin.sales-orders.index', [
            'orders' => $orders->paginate($filters)->withQueryString(),
            'filters' => $filters,
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function create(): View
    {
        return view('admin.sales-orders.create', $this->formData());
    }

    public function edit(SalesOrder $order): View
    {
        $this->ensureEditableDraft($order);
        $order->load(['buyer:id,name,email,phone', 'items']);

        return view('admin.sales-orders.create', $this->formData($order));
    }

    public function options(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['buyer', 'sku'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim($data['search'] ?? '');

        if ($data['type'] === 'buyer') {
            $buyers = User::query()->where('role', User::ROLE_CUSTOMER)
                ->when($search !== '', fn ($query) => $query->where(fn ($matches) => $matches
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')))
                ->orderBy('name')->limit(20)->get(['id', 'name', 'email', 'phone']);

            return response()->json(['data' => $buyers]);
        }

        $variants = ProductVariant::query()->with(['product:id,name', 'unit:id,symbol'])
            ->where('status', 'active')->where('sellable_retail', true)->where('track_inventory', true)
            ->whereHas('product', fn ($query) => $query->where('status', 'active')->where('gift_only', false))
            ->when($search !== '', fn ($query) => $query->where(fn ($matches) => $matches
                ->where('sku', 'like', '%'.$search.'%')
                ->orWhere('variant_name', 'like', '%'.$search.'%')
                ->orWhereHas('product', fn ($products) => $products->where('name', 'like', '%'.$search.'%'))))
            ->orderBy('sku')->limit(20)->get();

        return response()->json(['data' => $variants->map(fn (ProductVariant $variant): array => [
            'sku' => $variant->sku,
            'label' => $variant->product->name.' · '.$variant->variant_name.' · '.$variant->sku,
            'unit' => $variant->unit?->symbol,
        ])]);
    }

    public function preview(Request $request, SalesOrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.sku' => ['required', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);
        $preview = $this->runDomain(fn (): array => $orders->previewRetail($data));

        return redirect()->route('admin.web.sales.orders.create')
            ->withInput($request->except('_token'))->with('preview', $preview);
    }

    public function previewDraft(Request $request, SalesOrder $order, SalesOrderService $orders): RedirectResponse
    {
        $this->ensureEditableDraft($order);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.sku' => ['required', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);
        $preview = $this->runDomain(fn (): array => $orders->previewRetail($data));

        return redirect()->route('admin.web.sales.orders.edit', $order)
            ->withInput($request->except('_token'))->with('preview', $preview);
    }

    public function store(CreateBladeSalesOrderRequest $request, SalesOrderService $orders): RedirectResponse
    {
        $order = $this->runDomain(fn (): SalesOrder => $orders->createDraft($request->validated(), $request->user()->id));

        return redirect()->route('admin.web.sales.orders.show', $order)
            ->with('success', 'Đã lưu đơn bán lẻ nháp '.$order->order_code.'.');
    }

    public function updateDraft(CreateBladeSalesOrderRequest $request, SalesOrder $order, SalesOrderService $orders): RedirectResponse
    {
        $updated = $this->runDomain(fn (): SalesOrder => $orders->updateRetailDraft($order, $request->validated(), $request->user()->id));

        return redirect()->route('admin.web.sales.orders.show', $updated)
            ->with('success', 'Đã cập nhật đơn nháp '.$updated->order_code.'.');
    }

    public function show(SalesOrder $order, OrderPaymentSummaryService $summaries, InventoryReservationService $inventory): View
    {
        $order->load(['buyer:id,name,email,phone', 'warehouse:id,code,name,status',
            'dealerAccount:id,code,legal_name', 'items.reservation', 'histories.actor:id,name']);
        $summaries->attach($order);
        $paymentAllocations = PaymentAllocation::query()->with(['payment.recorder:id,name'])
            ->where('sales_order_id', $order->id)->orderByDesc('id')->get();
        $remainingQuantities = $order->items->mapWithKeys(fn ($item): array => [
            $item->id => $item->reservation === null ? '0.000' : $inventory->remaining($item->reservation),
        ])->all();
        $fulfillSuggestions = array_map(fn (string $remaining): string => bcdiv($remaining, '1', 0), $remainingQuantities);
        $canFulfill = $order->order_source !== 'cart'
            && in_array($order->order_status, ['confirmed', 'processing'], true)
            && collect($remainingQuantities)->contains(fn (string $remaining): bool => bccomp($remaining, '1', 3) >= 0);
        $advanceTarget = $order->sales_channel === 'retail' && $order->order_source === 'cart'
            ? match ($order->order_status) {
                'confirmed' => 'preparing',
                'preparing' => 'shipping',
                'shipping' => 'delivered',
                default => null,
            } : null;
        $derivedPaymentStatus = $summaries->status((string) $order->grand_total, (string) $order->paid_amount);
        $canRecordPayment = ! in_array($order->order_status, ['draft', 'cancelled'], true)
            && bccomp((string) $order->outstanding_amount, '0', 2) > 0
            && ! $summaries->hasUnsupportedLegacyMarker($order, (string) $order->paid_amount)
            && ! ($order->sales_channel === 'dealer' && $order->payment_method === 'dealer_wallet');

        $status = $order->order_status;
        $isRetail = $order->sales_channel === 'retail';
        $canCancelStatus = $isRetail
            ? in_array($status, ['draft', 'pending', 'confirmed', 'preparing'], true)
            : $order->sales_channel === 'dealer' && in_array($status, ['draft', 'confirmed'], true);
        $actions = [
            'confirm' => $isRetail ? in_array($status, ['draft', 'pending'], true)
                : $order->sales_channel === 'dealer' && $status === 'draft',
            'reprice' => $isRetail && $status === 'draft',
            'cancel' => $canCancelStatus && bccomp((string) $order->refundable_amount, '0', 2) === 0
                && ! $summaries->hasUnsupportedLegacyMarker($order, $summaries->paid($order)),
        ];
        $operationKeys = [];
        foreach ([...array_keys($actions), 'fulfill', 'advance', 'payment'] as $action) {
            $previousKey = old('lifecycle_action') === $action ? old('operation_key') : null;
            $operationKeys[$action] = is_string($previousKey) && Str::isUuid($previousKey)
                ? $previousKey : (string) Str::uuid();
        }

        return view('admin.sales-orders.show', [
            'order' => $order,
            'lifecycleActions' => $actions,
            'operationKeys' => $operationKeys,
            'remainingQuantities' => $remainingQuantities,
            'fulfillSuggestions' => $fulfillSuggestions,
            'canFulfill' => $canFulfill,
            'advanceTarget' => $advanceTarget,
            'canRecordPayment' => $canRecordPayment,
            'derivedPaymentStatus' => $derivedPaymentStatus,
            'paymentAllocations' => $paymentAllocations,
            'canEditDraft' => $order->sales_channel === 'retail' && $order->order_source === 'admin' && $status === 'draft',
        ]);
    }

    public function confirm(Request $request, SalesOrder $order, SalesOrderService $orders): RedirectResponse
    {
        $data = $request->validate(['operation_key' => ['required', 'uuid']]);
        $this->runDomain(fn (): SalesOrder => $orders->confirm($order, $data['operation_key'], $request->user()->id));

        return redirect()->route('admin.web.sales.orders.show', $order)
            ->with('success', 'Đã xác nhận đơn '.$order->order_code.'.');
    }

    public function reprice(Request $request, SalesOrder $order, SalesOrderService $orders): RedirectResponse
    {
        $data = $request->validate(['operation_key' => ['required', 'uuid']]);
        $this->runDomain(fn (): SalesOrder => $orders->repriceDraft($order, $data['operation_key'], $request->user()->id));

        return redirect()->route('admin.web.sales.orders.show', $order)
            ->with('success', 'Đã cập nhật giá đơn '.$order->order_code.'.');
    }

    public function cancel(Request $request, SalesOrder $order, SalesOrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $this->runDomain(fn (): SalesOrder => $orders->cancel($order, $data['operation_key'], $data['reason'], $request->user()->id));

        return redirect()->route('admin.web.sales.orders.show', $order)
            ->with('success', 'Đã hủy đơn '.$order->order_code.'.');
    }

    public function fulfill(FulfillSalesOrderRequest $request, SalesOrder $order, SalesOrderService $orders): RedirectResponse
    {
        $data = $request->validated();
        $quantities = [];
        foreach ($data['items'] as $item) {
            $quantities[(int) $item['item_id']] = (string) $item['quantity'];
        }
        $this->runDomain(fn (): SalesOrder => $orders->fulfill($order, $data['operation_key'], $quantities, $request->user()->id));

        return redirect()->route('admin.web.sales.orders.show', $order)
            ->with('success', 'Đã ghi nhận xuất hàng cho đơn '.$order->order_code.'.');
    }

    public function advance(Request $request, SalesOrder $order, SalesOrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'target' => ['required', Rule::in(['preparing', 'shipping', 'delivered', 'completed'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->runDomain(fn (): SalesOrder => $orders->retailAdvance($order, $data['operation_key'],
            $data['target'], $request->user()->id, $data['note'] ?? null));

        return redirect()->route('admin.web.sales.orders.show', $order)
            ->with('success', 'Đã cập nhật xử lý đơn '.$order->order_code.'.');
    }

    public function recordPayment(RecordPaymentRequest $request, SalesOrder $order, PaymentService $payments): RedirectResponse
    {
        if ($order->sales_channel === 'dealer' && $order->payment_method === 'dealer_wallet') {
            throw ValidationException::withMessages(['operation' => 'Thanh toán Ví đại lý được xử lý qua luồng ví hiện có.']);
        }
        $this->runDomain(fn () => $payments->recordSettledPayment($order, $request->validated(), $request->user()->id));

        return redirect()->route('admin.web.sales.orders.show', $order)
            ->with('success', 'Đã ghi nhận thanh toán cho đơn '.$order->order_code.'.');
    }

    /** @return array<string, mixed> */
    private function formData(?SalesOrder $editingOrder = null): array
    {
        $buyerId = old('buyer_user_id', $editingOrder?->buyer_user_id);
        $selectedBuyer = $buyerId === null ? null : User::query()->where('role', User::ROLE_CUSTOMER)
            ->find($buyerId, ['id', 'name', 'email', 'phone']);
        $inputItems = old('items', $editingOrder?->items->map(fn ($item): array => [
            'sku' => $item->sku_snapshot,
            'quantity' => preg_replace('/\.0+$/', '', (string) $item->quantity),
        ])->all() ?? []);
        $inputItems = is_array($inputItems) ? array_slice($inputItems, 0, 50) : [];
        $skus = collect($inputItems)->pluck('sku')->filter()->all();
        $variants = ProductVariant::query()->with('product:id,name')->whereIn('sku', $skus)->get()->keyBy('sku');
        $items = collect($inputItems)->filter(fn (mixed $item): bool => is_array($item))->map(function (array $item) use ($variants): array {
            $sku = (string) ($item['sku'] ?? '');
            $variant = $variants->get($sku);

            return ['sku' => $sku, 'quantity' => (string) ($item['quantity'] ?? '1'),
                'label' => $variant === null ? $sku : $variant->product?->name.' · '.$variant->variant_name.' · '.$sku];
        })->values()->all();

        return [
            'warehouses' => Warehouse::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name', 'is_default_sales']),
            'selectedBuyer' => $selectedBuyer,
            'initialItems' => $items,
            'operationKey' => old('operation_key', (string) Str::uuid()),
            'preview' => session('preview'),
            'editingOrder' => $editingOrder,
        ];
    }

    private function ensureEditableDraft(SalesOrder $order): void
    {
        abort_unless($order->sales_channel === 'retail'
            && $order->order_source === 'admin'
            && $order->order_status === 'draft', 404);
    }

    private function runDomain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $details = $exception->getResponse()->getData(true);
            $code = $details['code'] ?? 'ORDER_CONFLICT';
            $field = match ($code) {
                'WAREHOUSE_INACTIVE' => 'warehouse_id',
                'SKU_UNAVAILABLE', 'PRICE_NOT_FOUND', 'PRICE_AMBIGUOUS', 'GIFT_ONLY_PRODUCT_NOT_PURCHASABLE' => 'items',
                default => 'operation',
            };
            $message = match ($code) {
                'WAREHOUSE_INACTIVE' => 'Kho đã ngừng hoạt động.',
                'SKU_UNAVAILABLE' => 'SKU không còn khả dụng để bán lẻ.',
                'PRICE_NOT_FOUND' => 'SKU chưa có giá Retail hợp lệ.',
                'PRICE_AMBIGUOUS' => 'Có nhiều giá Retail cùng mức ưu tiên cho SKU.',
                'GIFT_ONLY_PRODUCT_NOT_PURCHASABLE' => 'Sản phẩm chỉ dùng làm quà tặng.',
                'ORDER_PRICE_CHANGED' => 'Giá Retail đã thay đổi. Hãy cập nhật giá đơn nháp rồi xác nhận lại.',
                'DEALER_ORDER_CHANGED' => 'Thông tin đại lý, cấp giá hoặc kho đã thay đổi. Hãy kiểm tra đơn trước khi xác nhận.',
                'UNSUPPORTED_CHANNEL' => 'Kênh bán của đơn không hỗ trợ thao tác này.',
                'ORDER_INVALID_STATE' => 'Trạng thái đơn không còn cho phép thao tác này. Hãy tải lại trang.',
                'INSUFFICIENT_STOCK' => 'Kho không đủ hàng cho SKU '.($details['sku'] ?? 'đã chọn').': cần '.($details['requested'] ?? '—').', còn '.($details['available'] ?? '—').'.',
                'PAID_ORDER_REQUIRES_REFUND' => 'Đơn đã có thanh toán. Cần xử lý hoàn tiền theo quy trình trước khi hủy.',
                'FULFILLMENT_EXCEEDS_RESERVATION' => 'Số lượng xuất vượt phần hàng còn giữ. Hãy tải lại đơn và kiểm tra số lượng.',
                'PAYMENT_AMOUNT_INVALID' => 'Số tiền phải lớn hơn 0 và có tối đa hai chữ số thập phân.',
                'PAYMENT_EXCEEDS_OUTSTANDING' => 'Số tiền vượt khoản còn phải thu của đơn. Hãy tải lại trang.',
                'ORDER_ALREADY_PAID' => 'Đơn đã được thanh toán đủ. Hãy tải lại trang.',
                'PAYMENT_OPERATION_CONFLICT' => 'Mã thao tác đã được dùng cho khoản thanh toán khác. Hãy tải lại trang.',
                'PAYMENT_EXTERNAL_REFERENCE_ALREADY_USED' => 'Mã tham chiếu đã được dùng cho khoản thanh toán khác.',
                'PAYMENT_LEGACY_STATUS_REQUIRES_REVIEW' => 'Trạng thái thanh toán cũ cần được kiểm tra trước khi ghi nhận thêm.',
                'PAYMENT_CURRENCY_MISMATCH' => 'Đơn có khoản thanh toán khác đơn vị tiền tệ; cần kiểm tra trước khi ghi nhận thêm.',
                'OPERATION_KEY_CONFLICT' => 'Mã thao tác đã được dùng với nội dung khác. Hãy tải lại biểu mẫu.',
                default => 'Không thể xử lý đơn hàng: '.$code.'.',
            };
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
