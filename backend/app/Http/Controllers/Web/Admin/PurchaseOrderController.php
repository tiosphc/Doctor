<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecordPurchasePaymentRequest;
use App\Http\Requests\Admin\SavePurchaseOrderRequest;
use App\Http\Requests\Admin\StoreGoodsReceiptRequest;
use App\Http\Requests\Admin\StorePurchaseReturnRequest;
use App\Models\GoodsReceipt;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\ProcurementService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'status' => ['nullable', 'in:draft,ordered,partially_received,received,cancelled'],
        ]);
        $orders = PurchaseOrder::query()->with(['supplier:id,code,name', 'warehouse:id,code,name'])->withCount('items')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('code', 'like', '%'.$search.'%'))
            ->when($filters['supplier_id'] ?? null, fn ($query, $id) => $query->where('supplier_id', $id))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));

        return view('admin.purchase-orders.index', [
            'orders' => $orders->latest('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function create(): View
    {
        return view('admin.purchase-orders.create', $this->formData());
    }

    public function store(SavePurchaseOrderRequest $request, ProcurementService $procurement): RedirectResponse
    {
        $order = $this->runDomain(fn () => $procurement->createOrder($request->validated(), $request->user()->id));

        return redirect()->route('admin.web.purchase-orders.show', $order)->with('success', 'Đã tạo phiếu mua nháp.');
    }

    public function show(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load(['supplier:id,code,name,status', 'warehouse:id,code,name,status', 'items.variant.product:id,name', 'items.variant.unit:id,name,symbol,decimal_precision']);

        return view('admin.purchase-orders.show', [
            'order' => $purchaseOrder,
            'receipts' => GoodsReceipt::query()->where('purchase_order_id', $purchaseOrder->id)
                ->with('items.orderItem:id,product_variant_id,sku_snapshot')->latest('id')->paginate(10, ['*'], 'receipts_page')->withQueryString(),
            'returns' => PurchaseReturn::query()->where('purchase_order_id', $purchaseOrder->id)
                ->with('items.receiptItem.orderItem:id,product_variant_id,sku_snapshot')->latest('id')->paginate(10, ['*'], 'returns_page')->withQueryString(),
            'returnableReceipts' => in_array($purchaseOrder->status, ['partially_received', 'received'], true)
                ? GoodsReceipt::query()->where('purchase_order_id', $purchaseOrder->id)->with(['items' => fn ($query) => $query->whereColumn('returned_quantity', '<', 'quantity')->with('orderItem:id,product_variant_id,sku_snapshot')])->get()
                : collect(),
            'payments' => $purchaseOrder->payments()->latest('id')->paginate(10, ['*'], 'payments_page')->withQueryString(),
            'operationKeys' => [
                'receipt' => old('_operation') === 'receipt' ? old('operation_key') : (string) Str::uuid(),
                'return' => old('_operation') === 'return' ? old('operation_key') : (string) Str::uuid(),
                'payment' => old('_operation') === 'payment' ? old('operation_key') : (string) Str::uuid(),
            ],
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): View
    {
        abort_unless($purchaseOrder->status === 'draft', 404);
        $purchaseOrder->load('items');

        return view('admin.purchase-orders.edit', ['order' => $purchaseOrder, ...$this->formData($purchaseOrder)]);
    }

    public function update(SavePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, ProcurementService $procurement): RedirectResponse
    {
        $this->runDomain(fn () => $procurement->updateOrder($purchaseOrder, $request->validated()));

        return redirect()->route('admin.web.purchase-orders.show', $purchaseOrder)->with('success', 'Đã cập nhật phiếu mua.');
    }

    public function issue(PurchaseOrder $purchaseOrder, Request $request, ProcurementService $procurement): RedirectResponse
    {
        $this->runDomain(fn () => $procurement->issueOrder($purchaseOrder, $request->user()->id));

        return $this->showRedirect($purchaseOrder, 'Đã phát hành phiếu mua.');
    }

    public function cancel(PurchaseOrder $purchaseOrder, ProcurementService $procurement): RedirectResponse
    {
        $this->runDomain(fn () => $procurement->cancelOrder($purchaseOrder));

        return $this->showRedirect($purchaseOrder, 'Đã hủy phiếu mua.');
    }

    public function receive(StoreGoodsReceiptRequest $request, PurchaseOrder $purchaseOrder, ProcurementService $procurement): RedirectResponse
    {
        $this->runDomain(fn () => $procurement->receive($purchaseOrder, $request->validated(), $request->user()->id));

        return $this->showRedirect($purchaseOrder, 'Đã nhận hàng vào kho của phiếu mua.');
    }

    public function returnGoods(StorePurchaseReturnRequest $request, PurchaseOrder $purchaseOrder, ProcurementService $procurement): RedirectResponse
    {
        $this->runDomain(fn () => $procurement->returnGoods($purchaseOrder, $request->validated(), $request->user()->id));

        return $this->showRedirect($purchaseOrder, 'Đã ghi nhận trả hàng.');
    }

    public function recordPayment(RecordPurchasePaymentRequest $request, PurchaseOrder $purchaseOrder, ProcurementService $procurement): RedirectResponse
    {
        $this->runDomain(fn () => $procurement->recordPayment($purchaseOrder, $request->validated(), $request->user()->id));

        return $this->showRedirect($purchaseOrder, 'Đã ghi nhận thanh toán ngoài hệ thống.');
    }

    /** @return array<string, mixed> */
    private function formData(?PurchaseOrder $order = null): array
    {
        return [
            'suppliers' => Supplier::query()->where(fn ($query) => $query->where('status', 'active')->when($order, fn ($query) => $query->orWhereKey($order->supplier_id)))->orderBy('name')->get(['id', 'code', 'name', 'status']),
            'warehouses' => Warehouse::query()->where(fn ($query) => $query->where('status', 'active')->when($order, fn ($query) => $query->orWhereKey($order->warehouse_id)))->orderBy('name')->get(['id', 'code', 'name', 'status']),
            'variants' => ProductVariant::query()->with(['product:id,name,status', 'unit:id,name,symbol'])
                ->where(fn ($query) => $query->where('status', 'active')->where('track_inventory', true)
                    ->whereHas('product', fn ($query) => $query->where('status', '!=', 'inactive'))
                    ->when($order, fn ($query) => $query->orWhereIn('id', $order->items->pluck('product_variant_id'))))
                ->orderBy('sku')->get(['id', 'product_id', 'unit_id', 'sku', 'variant_name', 'status']),
        ];
    }

    private function showRedirect(PurchaseOrder $order, string $message): RedirectResponse
    {
        return redirect()->route('admin.web.purchase-orders.show', $order)->with('success', $message);
    }

    private function runDomain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() === 409) {
                $code = $exception->getResponse()->getData(true)['code'] ?? 'PROCUREMENT_CONFLICT';
                throw ValidationException::withMessages(['operation' => "Không thể thực hiện thao tác: {$code}."]);
            }
            throw $exception;
        }
    }
}
