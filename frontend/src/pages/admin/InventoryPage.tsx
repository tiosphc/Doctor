import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { inventoryApi, inventoryKeys, type InventoryOperation } from "@/services/inventoryApi";
import { productApi } from "@/services/productApi";
import type { InventoryBalance, StockMovement } from "@/types/inventory";
import {
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "./ProductAdminShared";

type Tab = "balances" | "movements" | "reconciliation";
type Mode = "opening" | "receipt" | "adjustment";

const operationNames: Record<Mode, string> = {
    opening: "Tồn đầu kỳ",
    receipt: "Nhập kho",
    adjustment: "Điều chỉnh tồn",
};
const movementNames: Record<StockMovement["movement_type"], string> = {
    OPENING_BALANCE: "Tồn đầu kỳ",
    GOODS_RECEIPT: "Nhập kho",
    ADJUSTMENT_IN: "Điều chỉnh tăng",
    ADJUSTMENT_OUT: "Điều chỉnh giảm",
};

function milli(value: string): bigint | null {
    if (!/^-?\d+(?:\.\d{1,3})?$/.test(value)) return null;
    const negative = value.startsWith("-");
    const [whole, fraction = ""] = (negative ? value.slice(1) : value).split(".");
    const amount = BigInt(whole ?? "0") * 1000n + BigInt(fraction.padEnd(3, "0"));
    return negative ? -amount : amount;
}

function fixed(amount: bigint): string {
    const negative = amount < 0n;
    const positive = negative ? -amount : amount;
    return `${negative ? "-" : ""}${positive / 1000n}.${String(positive % 1000n).padStart(3, "0")}`;
}

function when(value: string | null): string {
    return value ? new Date(value).toLocaleString("vi-VN") : "—";
}

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
    const [mode, setMode] = useState<Mode>("receipt");
    const [operationWarehouse, setOperationWarehouse] = useState("");
    const [productSearch, setProductSearch] = useState("");
    const [productId, setProductId] = useState("");
    const [variantId, setVariantId] = useState("");
    const [quantity, setQuantity] = useState("");
    const [reasonCode, setReasonCode] = useState("");
    const [reasonDetail, setReasonDetail] = useState("");
    const [referenceId, setReferenceId] = useState("");
    const [operationKey, setOperationKey] = useState(() => crypto.randomUUID());
    const [confirm, setConfirm] = useState(false);
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});

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
    const products = useQuery({
        queryKey: ["inventory-products", productSearch],
        queryFn: () => productApi.adminProducts({ search: productSearch, page: 1 }),
    });
    const selectedProductQuery = useQuery({
        queryKey: ["inventory-product", productId],
        queryFn: () => productApi.adminProduct(Number(productId)),
        enabled: Boolean(productId),
    });
    const selectedProduct =
        selectedProductQuery.data?.data ??
        products.data?.data.find((item) => item.id === Number(productId));
    const eligibleVariants =
        selectedProduct?.variants.filter(
            (item) => item.track_inventory && item.status === "active",
        ) ?? [];
    const currentBalance = useQuery({
        queryKey: inventoryKeys.balances({
            warehouse_id: Number(operationWarehouse),
            product_variant_id: Number(variantId),
        }),
        queryFn: () =>
            inventoryApi.balances({
                warehouse_id: Number(operationWarehouse),
                product_variant_id: Number(variantId),
            }),
        enabled: Boolean(operationWarehouse && variantId),
    });
    const current = currentBalance.data?.data[0]?.on_hand_quantity ?? "0.000";
    const change = milli(quantity);
    const projected = change === null ? null : fixed((milli(current) ?? 0n) + change);
    const operation = useMutation({
        mutationFn: () => {
            const body: InventoryOperation = {
                warehouse_id: Number(operationWarehouse),
                product_variant_id: Number(variantId),
                quantity,
                operation_key: operationKey,
                reason_detail: reasonDetail,
                ...(reasonCode ? { reason_code: reasonCode } : {}),
                ...(referenceId
                    ? { reference_type: "MANUAL" as const, reference_id: referenceId }
                    : {}),
            };
            return mode === "opening"
                ? inventoryApi.opening(body)
                : mode === "receipt"
                  ? inventoryApi.receipt(body)
                  : inventoryApi.adjustment(body);
        },
        onSuccess: async () => {
            setConfirm(false);
            setQuantity("");
            setReasonCode("");
            setReasonDetail("");
            setReferenceId("");
            setOperationKey(crypto.randomUUID());
            setErrors({});
            setNotice("Đã ghi movement và cập nhật tồn kho.");
            await Promise.all([
                client.invalidateQueries({ queryKey: ["inventory-balances"] }),
                client.invalidateQueries({ queryKey: ["stock-movements"] }),
                client.invalidateQueries({ queryKey: ["inventory-reconciliation"] }),
            ]);
        },
    });
    function changeInput(action: () => void) {
        action();
        setOperationKey(crypto.randomUUID());
        setErrors({});
        setNotice("");
    }
    function selectBalance(row: InventoryBalance, nextMode: Mode) {
        setMode(nextMode);
        setOperationWarehouse(String(row.warehouse_id));
        setProductSearch(row.product.name);
        setProductId(String(row.product.id));
        setVariantId(String(row.product_variant_id));
        setQuantity("");
        setOperationKey(crypto.randomUUID());
        setNotice("");
        document.getElementById("inventory-operation")?.scrollIntoView({ behavior: "smooth" });
    }

    return (
        <ProductAdminGuard>
            <div className="space-y-7">
                <header>
                    <p className="label-luxury">Inventory Core</p>
                    <h1 className="mt-2 text-3xl text-primary">Tồn kho theo Kho / SKU</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Số dư được tính từ nghiệp vụ có movement. Chưa có reservation, đơn hàng hoặc
                        tiêu hao phòng khám.
                    </p>
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
                                  ? "Lịch sử movement"
                                  : "Đối soát"}
                        </button>
                    ))}
                </div>
                <label className="block max-w-sm text-sm">
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
                    <section className="rounded-xl border bg-card p-5">
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
                                />{" "}
                                Chỉ tồn thấp
                            </label>
                        </div>
                        {balances.isPending ? (
                            <LoadingState />
                        ) : balances.isError ? (
                            <ErrorState
                                message={errorMessage(balances.error)}
                                retry={() => balances.refetch()}
                            />
                        ) : balances.data.data.length === 0 ? (
                            <div className="mt-4">
                                <EmptyState message="Chưa có số dư phù hợp. Số dư mới được tạo qua thao tác nhập kho hoặc tồn đầu kỳ." />
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
                                                <th className="p-3">On hand</th>
                                                <th className="p-3">Reserved</th>
                                                <th className="p-3">Available</th>
                                                <th className="p-3">Movement gần nhất</th>
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
                                                        {(row.variant.status !== "active" ||
                                                            row.product.status !== "active") && (
                                                            <span className="block text-xs text-muted-foreground">
                                                                SKU/Product ngừng hoạt động
                                                            </span>
                                                        )}
                                                        {row.low_stock && (
                                                            <span className="text-amber-700">
                                                                Tồn thấp
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="p-3">{row.warehouse.code}</td>
                                                    <td className="p-3">{row.unit.symbol}</td>
                                                    <td className="p-3 tabular-nums">
                                                        {row.on_hand_quantity}
                                                    </td>
                                                    <td className="p-3 tabular-nums">
                                                        {row.reserved_quantity}
                                                    </td>
                                                    <td className="p-3 tabular-nums">
                                                        {row.available_quantity}
                                                    </td>
                                                    <td className="p-3">
                                                        {when(row.last_movement_at)}
                                                    </td>
                                                    <td className="p-3">
                                                        <div className="flex flex-wrap gap-2">
                                                            <button
                                                                type="button"
                                                                className="text-primary underline"
                                                                onClick={() => {
                                                                    setMovementVariantId(
                                                                        String(
                                                                            row.product_variant_id,
                                                                        ),
                                                                    );
                                                                    setWarehouseFilter(
                                                                        String(row.warehouse_id),
                                                                    );
                                                                    setMovementPage(1);
                                                                    setTab("movements");
                                                                }}
                                                            >
                                                                Lịch sử
                                                            </button>
                                                            <button
                                                                type="button"
                                                                className="text-primary underline"
                                                                onClick={() =>
                                                                    selectBalance(row, "receipt")
                                                                }
                                                            >
                                                                Nhập
                                                            </button>
                                                            <button
                                                                type="button"
                                                                className="text-primary underline"
                                                                onClick={() =>
                                                                    selectBalance(row, "adjustment")
                                                                }
                                                            >
                                                                Điều chỉnh
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
                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="text-xl text-primary">Lịch sử movement · chỉ đọc</h2>
                        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <label className="text-sm">
                                SKU ID
                                <input
                                    type="number"
                                    min="1"
                                    className={fieldClass}
                                    value={movementVariantId}
                                    onChange={(event) => {
                                        setMovementVariantId(event.target.value);
                                        setMovementPage(1);
                                    }}
                                />
                            </label>
                            <label className="text-sm">
                                Loại
                                <select
                                    className={fieldClass}
                                    value={movementType}
                                    onChange={(event) => {
                                        setMovementType(event.target.value);
                                        setMovementPage(1);
                                    }}
                                >
                                    <option value="">Tất cả</option>
                                    {Object.entries(movementNames).map(([key, label]) => (
                                        <option key={key} value={key}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-sm">
                                Từ ngày
                                <input
                                    type="date"
                                    className={fieldClass}
                                    value={movementFrom}
                                    onChange={(event) => {
                                        setMovementFrom(event.target.value);
                                        setMovementPage(1);
                                    }}
                                />
                            </label>
                            <label className="text-sm">
                                Đến ngày
                                <input
                                    type="date"
                                    className={fieldClass}
                                    value={movementTo}
                                    onChange={(event) => {
                                        setMovementTo(event.target.value);
                                        setMovementPage(1);
                                    }}
                                />
                            </label>
                            <label className="text-sm">
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
                                retry={() => movements.refetch()}
                            />
                        ) : movements.data.data.length === 0 ? (
                            <div className="mt-4">
                                <EmptyState message="Chưa có movement phù hợp." />
                            </div>
                        ) : (
                            <>
                                <div className="mt-4 space-y-3">
                                    {movements.data.data.map((item) => (
                                        <MovementRow key={item.id} item={item} />
                                    ))}
                                </div>
                                <Pagination
                                    current={movements.data.current_page}
                                    last={movements.data.last_page}
                                    onPage={setMovementPage}
                                />
                            </>
                        )}
                    </section>
                )}
                {tab === "reconciliation" && (
                    <section className="rounded-xl border bg-card p-5">
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
                                retry={() => reconciliation.refetch()}
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
                                                        {row.actual_on_hand ?? "Thiếu số dư"}
                                                    </td>
                                                    <td className="p-2">{row.expected_on_hand}</td>
                                                    <td className="p-2">{row.difference ?? "—"}</td>
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
                <section id="inventory-operation" className="rounded-xl border bg-card p-5">
                    <h2 className="text-xl text-primary">Ghi nhận stock operation</h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Mỗi lần xác nhận tạo một movement bất biến và cập nhật số dư trong cùng giao
                        dịch.
                    </p>
                    {notice && (
                        <p role="status" className="mt-3 text-sm text-primary">
                            {notice}
                        </p>
                    )}
                    <div className="mt-4 flex flex-wrap gap-2">
                        {(["opening", "receipt", "adjustment"] as const).map((value) => (
                            <button
                                key={value}
                                type="button"
                                className={mode === value ? buttonClass : secondaryButtonClass}
                                onClick={() => changeInput(() => setMode(value))}
                            >
                                {operationNames[value]}
                            </button>
                        ))}
                    </div>
                    <form
                        className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            setErrors({});
                            setNotice("");
                            setConfirm(true);
                        }}
                    >
                        <label className="text-sm">
                            Kho
                            <select
                                required
                                className={fieldClass}
                                value={operationWarehouse}
                                onChange={(event) =>
                                    changeInput(() => setOperationWarehouse(event.target.value))
                                }
                            >
                                <option value="">Chọn kho</option>
                                {warehouses.data?.data
                                    .filter((item) => item.status === "active")
                                    .map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.code} · {item.name}
                                        </option>
                                    ))}
                            </select>
                            {errors["warehouse_id"] && (
                                <span className="text-red-700">{errors["warehouse_id"]}</span>
                            )}
                        </label>
                        <label className="text-sm">
                            Tìm sản phẩm
                            <input
                                className={fieldClass}
                                value={productSearch}
                                onChange={(event) =>
                                    changeInput(() => {
                                        setProductSearch(event.target.value);
                                        setProductId("");
                                        setVariantId("");
                                    })
                                }
                                placeholder="Tên, mã hoặc SKU"
                            />
                        </label>
                        <label className="text-sm">
                            Sản phẩm
                            <select
                                required
                                className={fieldClass}
                                value={productId}
                                onChange={(event) =>
                                    changeInput(() => {
                                        setProductId(event.target.value);
                                        setVariantId("");
                                    })
                                }
                            >
                                <option value="">Chọn sản phẩm</option>
                                {products.data?.data
                                    .filter((item) => item.status !== "inactive")
                                    .map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.product_code} · {item.name}
                                        </option>
                                    ))}
                            </select>
                        </label>
                        <label className="text-sm">
                            SKU theo dõi tồn
                            <select
                                required
                                className={fieldClass}
                                value={variantId}
                                onChange={(event) =>
                                    changeInput(() => setVariantId(event.target.value))
                                }
                            >
                                <option value="">Chọn SKU</option>
                                {eligibleVariants.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.sku} · {item.variant_name}
                                    </option>
                                ))}
                            </select>
                            {errors["product_variant_id"] && (
                                <span className="text-red-700">{errors["product_variant_id"]}</span>
                            )}
                        </label>
                        <label className="text-sm">
                            Số lượng {mode === "adjustment" ? "(+ tăng / - giảm)" : "(dương)"}
                            <input
                                required
                                inputMode="decimal"
                                className={fieldClass}
                                value={quantity}
                                onChange={(event) =>
                                    changeInput(() => setQuantity(event.target.value))
                                }
                                placeholder={mode === "adjustment" ? "-2 hoặc 2" : "2"}
                            />
                            {errors["quantity"] && (
                                <span className="text-red-700">{errors["quantity"]}</span>
                            )}
                        </label>
                        <label className="text-sm">
                            Mã lý do {mode === "adjustment" ? "*" : ""}
                            <input
                                required={mode === "adjustment"}
                                className={fieldClass}
                                value={reasonCode}
                                onChange={(event) =>
                                    changeInput(() => setReasonCode(event.target.value))
                                }
                                placeholder="COUNT_CORRECTION"
                            />
                            {errors["reason_code"] && (
                                <span className="text-red-700">{errors["reason_code"]}</span>
                            )}
                        </label>
                        <label className="text-sm sm:col-span-2">
                            Lý do / ghi chú {mode !== "receipt" ? "*" : ""}
                            <textarea
                                required={mode !== "receipt"}
                                className={fieldClass}
                                value={reasonDetail}
                                onChange={(event) =>
                                    changeInput(() => setReasonDetail(event.target.value))
                                }
                            />
                            {errors["reason_detail"] && (
                                <span className="text-red-700">{errors["reason_detail"]}</span>
                            )}
                        </label>
                        <label className="text-sm">
                            Tham chiếu vận hành (tùy chọn)
                            <input
                                className={fieldClass}
                                value={referenceId}
                                onChange={(event) =>
                                    changeInput(() => setReferenceId(event.target.value))
                                }
                            />
                        </label>
                        <div className="sm:col-span-2 lg:col-span-3">
                            <button
                                className={buttonClass}
                                disabled={currentBalance.isFetching && Boolean(variantId)}
                            >
                                Xem và xác nhận
                            </button>
                        </div>
                    </form>
                </section>
                {confirm && (
                    <div
                        className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
                        role="presentation"
                    >
                        <div
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="inventory-confirm-title"
                            className="w-full max-w-lg space-y-4 rounded-xl bg-card p-6 shadow-xl"
                        >
                            <h2 id="inventory-confirm-title" className="text-xl text-primary">
                                Xác nhận {operationNames[mode].toLowerCase()}
                            </h2>
                            <dl className="grid grid-cols-2 gap-2 text-sm">
                                <dt>Kho</dt>
                                <dd>
                                    {
                                        warehouses.data?.data.find(
                                            (item) => item.id === Number(operationWarehouse),
                                        )?.code
                                    }
                                </dd>
                                <dt>SKU</dt>
                                <dd>
                                    {
                                        eligibleVariants.find(
                                            (item) => item.id === Number(variantId),
                                        )?.sku
                                    }
                                </dd>
                                <dt>Hiện tại</dt>
                                <dd>{current}</dd>
                                <dt>Thay đổi</dt>
                                <dd>{quantity}</dd>
                                <dt>Sau thao tác</dt>
                                <dd
                                    className={
                                        projected?.startsWith("-")
                                            ? "text-red-700"
                                            : "font-semibold"
                                    }
                                >
                                    {projected ?? "Không hợp lệ"}
                                </dd>
                                <dt>Lý do</dt>
                                <dd>{reasonCode || reasonDetail || "—"}</dd>
                            </dl>
                            <p className="text-xs text-muted-foreground">
                                Máy chủ sẽ kiểm tra lại số dư, quyền và dữ liệu trước khi ghi.
                            </p>
                            <div className="flex justify-end gap-3">
                                <button
                                    type="button"
                                    className={secondaryButtonClass}
                                    onClick={() => setConfirm(false)}
                                >
                                    Hủy
                                </button>
                                <button
                                    type="button"
                                    className={buttonClass}
                                    disabled={
                                        operation.isPending ||
                                        change === null ||
                                        change === 0n ||
                                        projected?.startsWith("-")
                                    }
                                    onClick={async () => {
                                        try {
                                            await operation.mutateAsync();
                                        } catch (reason) {
                                            setConfirm(false);
                                            setNotice(errorMessage(reason));
                                            setErrors(firstFieldErrors(reason));
                                        }
                                    }}
                                >
                                    {operation.isPending ? "Đang ghi..." : "Xác nhận"}
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </ProductAdminGuard>
    );
}

function MovementRow({ item }: { item: StockMovement }) {
    return (
        <div className="grid gap-2 rounded-lg border p-4 text-sm sm:grid-cols-[minmax(0,1fr)_auto]">
            <div>
                <p className="font-semibold">
                    {movementNames[item.movement_type]} ·{" "}
                    <span className="font-mono">{item.variant.sku}</span> · {item.warehouse.code}
                </p>
                <p className="mt-1 text-muted-foreground">
                    {item.quantity} · {item.before_on_hand_quantity} → {item.after_on_hand_quantity}
                </p>
                <p className="text-muted-foreground">
                    {item.reason_code || ""} {item.reason_detail || ""}
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
