<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BladeInventoryOperationRequest;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\AdminInventoryReadService;
use App\Services\InventoryReconciliationService;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InventoryController extends Controller
{
    private const MOVEMENT_TYPES = ['OPENING_BALANCE', 'GOODS_RECEIPT', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT', 'PURCHASE_RETURN', 'SALES_ORDER_SHIPMENT', 'SALES_RETURN'];

    public function index(Request $request, AdminInventoryReadService $inventory): View
    {
        $filters = $request->validate([
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'search' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'low_stock' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return view('admin.inventory.index', [
            ...$inventory->overview($filters),
            'filters' => $filters,
            'warehouses' => $this->warehouses(),
        ]);
    }

    public function show(ProductVariant $variant, Request $request, AdminInventoryReadService $inventory): View
    {
        $filters = $this->movementFilters($request);
        $variant->load('product:id,product_code,name,status,track_inventory,default_low_stock_threshold', 'unit:id,name,symbol,decimal_precision');
        $filters['product_variant_id'] = $variant->id;

        return view('admin.inventory.show', [
            'variant' => $variant,
            'balances' => $inventory->balancesFor($variant)->keyBy('warehouse_id'),
            'movements' => $inventory->movements($filters),
            'warehouses' => $this->warehouses(),
            'filters' => $filters,
            'inventory' => $inventory,
            'movementTypes' => self::MOVEMENT_TYPES,
        ]);
    }

    public function movements(Request $request, AdminInventoryReadService $inventory): View
    {
        $filters = $this->movementFilters($request);

        return view('admin.inventory.movements', [
            'movements' => $inventory->movements($filters),
            'warehouses' => $this->warehouses(),
            'filters' => $filters,
            'movementTypes' => self::MOVEMENT_TYPES,
        ]);
    }

    public function reconciliation(Request $request, InventoryReconciliationService $inventory): View
    {
        $filters = $request->validate(['warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')]]);

        return view('admin.inventory.reconciliation', [
            'report' => $inventory->report($filters['warehouse_id'] ?? null),
            'filters' => $filters,
            'warehouses' => $this->warehouses(),
        ]);
    }

    public function operation(ProductVariant $variant, Warehouse $warehouse, string $mode, AdminInventoryReadService $inventory): View
    {
        $this->assertMode($mode);
        $variant->load('product:id,product_code,name,status,track_inventory', 'unit:id,name,symbol,decimal_precision');
        $balance = $inventory->balancesFor($variant)->firstWhere('warehouse_id', $warehouse->id);
        $openingRecorded = $mode === 'opening' && DB::table('stock_movements')
            ->where('product_variant_id', $variant->id)->where('warehouse_id', $warehouse->id)->exists();

        return view('admin.inventory.operation', [
            'variant' => $variant,
            'warehouse' => $warehouse,
            'mode' => $mode,
            'balance' => $balance,
            'openingRecorded' => $mode === 'opening' && ($openingRecorded || ($balance !== null && bccomp($balance->on_hand_quantity, '0', 3) !== 0)),
            'operationKey' => old('operation_key', (string) Str::uuid()),
        ]);
    }

    public function storeOpening(BladeInventoryOperationRequest $request, ProductVariant $variant, Warehouse $warehouse, InventoryService $inventory): RedirectResponse
    {
        return $this->storeOperation($request, $variant, $warehouse, $inventory, 'opening');
    }

    public function storeAdjustment(BladeInventoryOperationRequest $request, ProductVariant $variant, Warehouse $warehouse, InventoryService $inventory): RedirectResponse
    {
        return $this->storeOperation($request, $variant, $warehouse, $inventory, 'adjustment');
    }

    public function storeReceipt(BladeInventoryOperationRequest $request, ProductVariant $variant, Warehouse $warehouse, InventoryService $inventory): RedirectResponse
    {
        return $this->storeOperation($request, $variant, $warehouse, $inventory, 'receipt');
    }

    private function storeOperation(BladeInventoryOperationRequest $request, ProductVariant $variant, Warehouse $warehouse, InventoryService $inventory, string $mode): RedirectResponse
    {
        $data = $request->validated();
        if ((int) $data['product_variant_id'] !== $variant->id || (int) $data['warehouse_id'] !== $warehouse->id) {
            throw ValidationException::withMessages(['inventory' => 'SKU hoặc kho đã thay đổi. Vui lòng tải lại biểu mẫu.']);
        }
        try {
            match ($mode) {
                'opening' => $inventory->opening($data, $request->user()->id),
                'adjustment' => $inventory->adjust($data, $request->user()->id),
                'receipt' => $inventory->receive($data, $request->user()->id),
            };
        } catch (HttpResponseException $exception) {
            $code = $exception->getResponse()->getData(true)['code'] ?? null;

            return back()->withInput()->withErrors(['inventory' => match ($code) {
                'OPENING_STOCK_ALREADY_RECORDED' => 'SKU này đã có lịch sử tồn tại kho. Hãy dùng điều chỉnh nếu cần sửa chênh lệch.',
                'INSUFFICIENT_AVAILABLE_STOCK' => 'Không thể giảm tồn thấp hơn số lượng đã giữ cho đơn hàng.',
                'WAREHOUSE_INACTIVE' => 'Kho đã ngừng hoạt động. Vui lòng chọn kho khác.',
                'SKU_NOT_INVENTORY_CAPABLE' => 'SKU không còn đủ điều kiện ghi tồn kho.',
                'OPERATION_KEY_CONFLICT' => 'Thao tác này đã thay đổi. Vui lòng tải lại biểu mẫu và thử lại.',
                default => 'Không thể ghi tồn kho. Vui lòng kiểm tra lại dữ liệu.',
            }]);
        }

        return redirect()->route('admin.web.inventory.show', $variant)
            ->with('success', match ($mode) {
                'opening' => 'Đã ghi tồn đầu kỳ.',
                'adjustment' => 'Đã điều chỉnh tồn kho.',
                'receipt' => 'Đã nhập kho thủ công.',
            });
    }

    /** @return array<string, mixed> */
    private function movementFilters(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'movement_type' => ['nullable', Rule::in(self::MOVEMENT_TYPES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'reference' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /** @return Collection<int, Warehouse> */
    private function warehouses(): Collection
    {
        return Warehouse::query()->orderBy('code')->get(['id', 'code', 'name', 'status']);
    }

    private function assertMode(string $mode): void
    {
        abort_unless(in_array($mode, ['opening', 'adjustment', 'receipt'], true), 404);
    }
}
