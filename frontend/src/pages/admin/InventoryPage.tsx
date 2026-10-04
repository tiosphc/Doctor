import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { errorMessage } from "@/services/api";
import { inventoryApi, inventoryKeys } from "@/services/inventoryApi";
import { formatProductQuantity } from "@/lib/productQuantity";
import type { InventoryBalance, StockMovement } from "@/types/inventory";
import {
    ProductAdminGuard,
    buttonClass,
    secondaryButtonClass,
    fieldClass,
} from "./ProductAdminShared";
import {
    InventoryOperationDrawer,
    stockReasonLabel,
    type InventoryDrawerSelection,
} from "./InventoryOperationDrawer";

type Tab = "balances" | "movements" | "reconciliation";
const movementNames: Record<StockMovement["movement_type"], string> = {
    OPENING_BALANCE: "Tồn đầu kỳ",
    GOODS_RECEIPT: "Nhận hàng",
    ADJUSTMENT_IN: "Điều chỉnh tăng",
    ADJUSTMENT_OUT: "Điều chỉnh giảm",
    PURCHASE_RETURN: "Trả hàng nhà cung cấp",
};
const when = (value: string | null) => (value ? new Date(value).toLocaleString("vi-VN") : "—");

export function InventoryPage() {
    const client = useQueryClient();
    const [tab, setTab] = useState<Tab>("balances");
    const [warehouseFilter, setWarehouseFilter] = useState("");
    const [search, setSearch] = useState("");
    const [lowStock, setLowStock] = useState(false);
    const [balancePage, setBalancePage] = useState(1);
    const [movementPage, setMovementPage] = useState(1);
    const [movementVariantId, setMovementVariantId] = useState("");
    const [movementType, setMovementType] = useState("");
    const [movementFrom, setMovementFrom] = useState("");
    const [movementTo, setMovementTo] = useState("");
    const [movementReference, setMovementReference] = useState("");
    const [drawer, setDrawer] = useState<InventoryDrawerSelection | null>(null);
    const warehouseId = warehouseFilter ? Number(warehouseFilter) : undefined;
    const balanceFilters = {
        warehouse_id: warehouseId,
        search,
        low_stock: lowStock || undefined,
        page: balancePage,
    };
    const movementFilters = {
        warehouse_id: warehouseId,
        product_variant_id: movementVariantId ? Number(movementVariantId) : undefined,
        movement_type: movementType || undefined,
        from: movementFrom || undefined,
        to: movementTo || undefined,
        reference: movementReference || undefined,
        page: movementPage,
    };
    const warehouses = useQuery({
        queryKey: inventoryKeys.warehouses({ per_page: 100 }),
        queryFn: () => inventoryApi.warehouses({ per_page: 100 }),
    });
    const balances = useQuery({
        queryKey: inventoryKeys.balances(balanceFilters),
        queryFn: () => inventoryApi.balances(balanceFilters),
        enabled: tab === "balances",
    });
    const movements = useQuery({
        queryKey: inventoryKeys.movements(movementFilters),
        queryFn: () => inventoryApi.movements(movementFilters),
        enabled: tab === "movements",
    });
    const reconciliation = useQuery({
        queryKey: inventoryKeys.reconciliation(warehouseId),
        queryFn: () => inventoryApi.reconciliation(warehouseId),
        enabled: tab === "reconciliation",
    });
    const refresh = async () => {
        await Promise.all([
            client.invalidateQueries({ queryKey: ["inventory-balances"] }),
            client.invalidateQueries({ queryKey: ["stock-movements"] }),
            client.invalidateQueries({ queryKey: ["inventory-reconciliation"] }),
        ]);
    };
    const openRow = (row: InventoryBalance, mode: InventoryDrawerSelection["mode"]) =>
        setDrawer({ mode, row });
    const openPageAction = (mode: InventoryDrawerSelection["mode"]) =>
        setDrawer({ mode, ...(warehouseId ? { warehouseId } : {}) });

    return (
        <ProductAdminGuard>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="label-luxury">Inventory Core</p>
                        <h1 className="mt-2 text-3xl text-primary">Tồn kho</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Theo dõi số dư, biến động và đối soát theo từng Kho / SKU.
                        </p>
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button type="button" className={buttonClass}>
                                Thao tác kho ▾
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onSelect={() => openPageAction("receipt")}>
                                Nhập kho thủ công
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => openPageAction("adjustment")}>
                                Điều chỉnh tồn
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => openPageAction("opening")}>
                                Thiết lập tồn đầu kỳ
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </header>
                <div className="flex flex-wrap gap-2">
                    {(["balances", "movements", "reconciliation"] as const).map((value) => (
                        <button
                            key={value}
                            type="button"
                            className={tab === value ? buttonClass : secondaryButtonClass}
                            onClick={() => setTab(value)}
                        >
                            {value === "balances"
                                ? "Số dư"
                                : value === "movements"
                                  ? "Lịch sử biến động"
                                  : "Đối soát"}
                        </button>
                    ))}
                </div>
                <label className="admin-form-field admin-form-label max-w-sm">
                    Kho
                    <select
                        className={fieldClass}
                        value={warehouseFilter}
                        onChange={(event) => {
                            setWarehouseFilter(event.target.value);
                            setBalancePage(1);
                            setMovementPage(1);
                        }}
                    >
                        <option value="">Tất cả kho</option>
                        {warehouses.data?.data.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.code} · {item.name}
                            </option>
                        ))}
                    </select>
                </label>
                {tab === "balances" && (
                    <section className="rounded-xl border bg-card p-4 sm:p-5">
                        <h2 className="text-xl text-primary">Số dư hiện tại</h2>
                        <div className="mt-4 flex flex-wrap items-end gap-3">
                            <label className="min-w-56 flex-1 text-sm">
                                Tìm SKU / sản phẩm
                                <input
                                    className={fieldClass}
                                    value={search}
                                    onChange={(event) => {
                                        setSearch(event.target.value);
                                        setBalancePage(1);
                                    }}
                                />
                            </label>
                            <label className="flex items-center gap-2 pb-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={lowStock}
                                    onChange={(event) => {
                                        setLowStock(event.target.checked);
                                        setBalancePage(1);
                                    }}
                                />
                                Chỉ tồn thấp
                            </label>
                        </div>
                        {balances.isPending ? (
                            <LoadingState />
                        ) : balances.isError ? (
                            <ErrorState
                                message={errorMessage(balances.error)}
                                retry={() => void balances.refetch()}
                            />
                        ) : balances.data.data.length === 0 ? (
                            <div className="mt-4">
                                <EmptyState
                                    message={
                                        warehouseFilter || search || lowStock
                                            ? "Không tìm thấy tồn kho phù hợp."
                                            : "Chưa có số dư tồn kho."
                                    }
                                />
                            </div>
                        ) : (
                            <>
                                <div className="mt-4 overflow-x-auto">
                                    <table className="w-full min-w-[860px] text-left text-sm">
                                        <thead className="border-b text-muted-foreground">
                                            <tr>
                                                <th className="p-3">SKU / Sản phẩm</th>
                                                <th className="p-3">Kho</th>
                                                <th className="p-3">Đơn vị</th>
                                                <th className="p-3">Tồn kho</th>
                                                <th className="p-3">Đã giữ</th>
                                                <th className="p-3">Khả dụng</th>
                                                <th className="p-3">Biến động gần nhất</th>
                                                <th className="p-3">Thao tác</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {balances.data.data.map((row) => (
                                                <tr key={row.id} className="border-b align-top">
                                                    <td className="p-3">
                                                        <strong className="font-mono">
                                                            {row.variant.sku}
                                                        </strong>
                                                        <span className="block text-muted-foreground">
                                                            {row.product.name}
                                                        </span>
                                                        {row.low_stock && (
                                                            <span className="text-xs text-amber-700">
                                                                Tồn thấp
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="p-3">{row.warehouse.name}</td>
                                                    <td className="p-3">{row.unit.symbol}</td>
                                                    <td className="p-3 tabular-nums">
                                                        {formatProductQuantity(
                                                            row.on_hand_quantity,
                                                        )}
                                                    </td>
                                                    <td className="p-3 tabular-nums">
                                                        {formatProductQuantity(
                                                            row.reserved_quantity,
                                                        )}
                                                    </td>
                                                    <td className="p-3 tabular-nums">
                                                        {formatProductQuantity(
                                                            row.available_quantity,
                                                        )}
                                                    </td>
                                                    <td className="p-3">
                                                        {when(row.last_movement_at)}
                                                    </td>
                                                    <td className="p-3">
                                                        <div className="flex flex-wrap gap-x-3 gap-y-1">
                                                            <button
                                                                type="button"
                                                                className="text-primary underline"
                                                                onClick={() =>
                                                                    openRow(row, "history")
                                                                }
                                                            >
                                                                Lịch sử
                                                            </button>
                                                            <button
                                                                type="button"
                                                                className="text-primary underline"
                                                                onClick={() =>
                                                                    openRow(row, "receipt")
                                                                }
                                                            >
                                                                Nhập kho thủ công
                                                            </button>
                                                            <button
                                                                type="button"
                                                                className="text-primary underline"
                                                                onClick={() =>
                                                                    openRow(row, "adjustment")
                                                                }
                                                            >
                                                                Điều chỉnh tồn
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <Pagination
                                    current={balances.data.current_page}
                                    last={balances.data.last_page}
                                    onPage={setBalancePage}
                                />
                            </>
                        )}
                    </section>
                )}
                {tab === "movements" && (
                    <section className="rounded-xl border bg-card p-4 sm:p-5">
                        <h2 className="text-xl text-primary">Lịch sử biến động kho</h2>
                        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <label className="admin-form-field admin-form-label">
                                ID biến thể
                                <input
                                    className={`${fieldClass} admin-field-compact`}
                                    type="number"
                                    min="1"
                                    value={movementVariantId}
                                    onChange={(event) => {
                                        setMovementVariantId(event.target.value);
                                        setMovementPage(1);
                                    }}
                                />
                            </label>
                            <label className="admin-form-field admin-form-label">
                                Loại biến động
                                <select
                                    className={fieldClass}
                                    value={movementType}
                                    onChange={(event) => {
                                        setMovementType(event.target.value);
                                        setMovementPage(1);
                                    }}
                                >
                                    <option value="">Tất cả</option>
                                    {Object.entries(movementNames).map(([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="admin-form-field admin-form-label">
                                Từ ngày
                                <input
                                    className={fieldClass}
                                    type="date"
                                    value={movementFrom}
                                    onChange={(event) => {
                                        setMovementFrom(event.target.value);
                                        setMovementPage(1);
                                    }}
                                />
                            </label>
                            <label className="admin-form-field admin-form-label">
                                Đến ngày
                                <input
                                    className={fieldClass}
                                    type="date"
                                    value={movementTo}
                                    onChange={(event) => {
                                        setMovementTo(event.target.value);
                                        setMovementPage(1);
                                    }}
                                />
                            </label>
                            <label className="admin-form-field admin-form-label">
                                Tham chiếu
                                <input
                                    className={fieldClass}
                                    value={movementReference}
                                    onChange={(event) => {
                                        setMovementReference(event.target.value);
                                        setMovementPage(1);
                                    }}
                                />
                            </label>
                        </div>
                        {movements.isPending ? (
                            <LoadingState />
                        ) : movements.isError ? (
                            <ErrorState
                                message={errorMessage(movements.error)}
                                retry={() => void movements.refetch()}
                            />
                        ) : movements.data.data.length === 0 ? (
                            <div className="mt-4">
                                <EmptyState message="Chưa có biến động phù hợp." />
                            </div>
                        ) : (
                            <div className="mt-4 space-y-3">
                                {movements.data.data.map((item) => (
                                    <MovementRow key={item.id} item={item} />
                                ))}
                                <Pagination
                                    current={movements.data.current_page}
                                    last={movements.data.last_page}
                                    onPage={setMovementPage}
                                />
                            </div>
                        )}
                    </section>
                )}
                {tab === "reconciliation" && (
                    <section className="rounded-xl border bg-card p-4 sm:p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-xl text-primary">Đối soát số dư với ledger</h2>
                            <button
                                type="button"
                                className={secondaryButtonClass}
                                onClick={() => void reconciliation.refetch()}
                            >
                                Kiểm tra lại
                            </button>
                        </div>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Báo cáo chỉ đọc, không tự sửa số dư.
                        </p>
                        {reconciliation.isPending ? (
                            <LoadingState />
                        ) : reconciliation.isError ? (
                            <ErrorState
                                message={errorMessage(reconciliation.error)}
                                retry={() => void reconciliation.refetch()}
                            />
                        ) : (
                            <>
                                <div className="mt-4 grid gap-3 sm:grid-cols-3">
                                    {(
                                        [
                                            "balances_checked",
                                            "movements_checked",
                                            "matched",
                                            "mismatched",
                                            "missing_balances",
                                            "orphan_movements",
                                        ] as const
                                    ).map((key) => (
                                        <div key={key} className="rounded-lg border p-4">
                                            <span className="block text-xs text-muted-foreground">
                                                {key.replaceAll("_", " ")}
                                            </span>
                                            <strong className="text-2xl">
                                                {reconciliation.data.data[key]}
                                            </strong>
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-4 overflow-x-auto">
                                    <table className="w-full min-w-[650px] text-left text-sm">
                                        <thead className="border-b">
                                            <tr>
                                                <th className="p-2">Kho / SKU</th>
                                                <th className="p-2">Thực tế</th>
                                                <th className="p-2">Ledger</th>
                                                <th className="p-2">Chênh lệch</th>
                                                <th className="p-2">Trạng thái</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {reconciliation.data.data.rows.map((row) => (
                                                <tr
                                                    key={`${row.warehouse_id}-${row.product_variant_id}`}
                                                    className="border-b"
                                                >
                                                    <td className="p-2">
                                                        {row.warehouse_code} / {row.sku}
                                                    </td>
                                                    <td className="p-2">
                                                        {row.actual_on_hand === null
                                                            ? "Thiếu số dư"
                                                            : formatProductQuantity(
                                                                  row.actual_on_hand,
                                                              )}
                                                    </td>
                                                    <td className="p-2">
                                                        {formatProductQuantity(
                                                            row.expected_on_hand,
                                                        )}
                                                    </td>
                                                    <td className="p-2">
                                                        {formatProductQuantity(row.difference)}
                                                    </td>
                                                    <td className="p-2">{row.status}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </>
                        )}
                    </section>
                )}
                {drawer && (
                    <InventoryOperationDrawer
                        key={`${drawer.mode}-${drawer.row?.id ?? "page"}-${drawer.warehouseId ?? ""}`}
                        selection={drawer}
                        warehouses={warehouses.data?.data ?? []}
                        onClose={() => setDrawer(null)}
                        onSuccess={refresh}
                    />
                )}
            </div>
        </ProductAdminGuard>
    );
}

function MovementRow({ item }: { item: StockMovement }) {
    const label =
        item.movement_type === "GOODS_RECEIPT" &&
        (item.reference_type === "MANUAL" || item.reason_code !== null)
            ? "Nhập kho thủ công"
            : movementNames[item.movement_type];
    return (
        <div className="grid gap-2 rounded-lg border p-4 text-sm sm:grid-cols-[minmax(0,1fr)_auto]">
            <div>
                <p className="font-semibold">
                    {label} · <span className="font-mono">{item.variant.sku}</span> ·{" "}
                    {item.warehouse.code}
                </p>
                <p className="mt-1 text-muted-foreground">
                    {formatProductQuantity(item.quantity)} ·{" "}
                    {formatProductQuantity(item.before_on_hand_quantity)} →{" "}
                    {formatProductQuantity(item.after_on_hand_quantity)}
                </p>
                <p className="text-muted-foreground">
                    {stockReasonLabel(item.reason_code)}
                    {item.reason_detail ? ` · ${item.reason_detail}` : ""}
                    {item.reference_id ? ` · ${item.reference_type}: ${item.reference_id}` : ""}
                </p>
            </div>
            <div className="text-muted-foreground sm:text-right">
                <p>{when(item.occurred_at)}</p>
                <p>{item.actor?.name || item.source}</p>
                <p>#{item.id}</p>
            </div>
        </div>
    );
}
