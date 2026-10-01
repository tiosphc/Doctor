import { useRef, useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { toast } from "sonner";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from "@/components/ui/sheet";
import { ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { inventoryApi, inventoryKeys, type InventoryOperation } from "@/services/inventoryApi";
import { productApi } from "@/services/productApi";
import {
    formatProductQuantity,
    isNonNegativeProductQuantity,
    isPositiveProductQuantity,
    isSignedProductQuantity,
} from "@/lib/productQuantity";
import type { InventoryBalance, StockMovement, Warehouse } from "@/types/inventory";
import { buttonClass, fieldClass, secondaryButtonClass } from "./ProductAdminShared";

export type InventoryDrawerMode = "opening" | "receipt" | "adjustment" | "history";
export type InventoryDrawerSelection = {
    mode: InventoryDrawerMode;
    row?: InventoryBalance;
    warehouseId?: number;
};

const titles: Record<InventoryDrawerMode, string> = {
    opening: "Thiết lập tồn đầu kỳ",
    receipt: "Nhập kho thủ công",
    adjustment: "Điều chỉnh tồn",
    history: "Lịch sử biến động kho",
};
const adjustmentReasons = [
    ["COUNT_CORRECTION", "Kiểm kê chênh lệch"],
    ["DAMAGED", "Hàng hư hỏng"],
    ["EXPIRED", "Hàng hết hạn"],
    ["LOST", "Mất hàng"],
    ["DATA_CORRECTION", "Sai dữ liệu"],
    ["OTHER", "Khác"],
] as const;
const inboundReasons = [
    ["SAMPLE", "Hàng mẫu"],
    ["GIFT", "Hàng tặng"],
    ["SPECIAL_INBOUND", "Nhập bổ sung đặc biệt"],
    ["MIGRATION", "Dữ liệu chuyển đổi"],
    ["OTHER", "Khác"],
] as const;
export const stockReasonLabel = (code: string | null): string =>
    [...adjustmentReasons, ...inboundReasons].find(([value]) => value === code)?.[1] ?? code ?? "";
const movementNames: Record<StockMovement["movement_type"], string> = {
    OPENING_BALANCE: "Tồn đầu kỳ",
    GOODS_RECEIPT: "Nhập kho",
    ADJUSTMENT_IN: "Điều chỉnh tăng",
    ADJUSTMENT_OUT: "Điều chỉnh giảm",
    PURCHASE_RETURN: "Trả hàng nhà cung cấp",
};

function wholeStock(value: string): bigint | null {
    const formatted = formatProductQuantity(value);
    return /^-?\d+$/.test(formatted) ? BigInt(formatted) : null;
}

export function InventoryOperationDrawer({
    selection,
    warehouses,
    onClose,
    onSuccess,
}: {
    selection: InventoryDrawerSelection;
    warehouses: Warehouse[];
    onClose: () => void;
    onSuccess: () => Promise<void>;
}) {
    const [mode, setMode] = useState(selection.mode);
    const [warehouseId, setWarehouseId] = useState(
        String(selection.row?.warehouse_id ?? selection.warehouseId ?? ""),
    );
    const [productSearch, setProductSearch] = useState("");
    const [productId, setProductId] = useState("");
    const [variantId, setVariantId] = useState(String(selection.row?.product_variant_id ?? ""));
    const [quantity, setQuantity] = useState("");
    const [reasonCode, setReasonCode] = useState("");
    const [note, setNote] = useState("");
    const [reference, setReference] = useState("");
    const [operationKey, setOperationKey] = useState(() => crypto.randomUUID());
    const [confirm, setConfirm] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [historyPage, setHistoryPage] = useState(1);
    const submitting = useRef(false);
    const readonlyRow = selection.row;

    const products = useQuery({
        queryKey: ["inventory-products", productSearch],
        queryFn: () => productApi.adminProducts({ search: productSearch, page: 1 }),
        enabled: !readonlyRow && mode !== "history",
    });
    const product = useQuery({
        queryKey: ["inventory-product", productId],
        queryFn: () => productApi.adminProduct(Number(productId)),
        enabled: Boolean(productId) && !readonlyRow,
    });
    const selectedProduct =
        product.data?.data ?? products.data?.data.find((item) => item.id === Number(productId));
    const variants =
        selectedProduct?.variants.filter(
            (item) => item.track_inventory && item.status === "active",
        ) ?? [];
    const selectedVariant = variants.find((item) => item.id === Number(variantId));
    const selectedWarehouse = warehouses.find((item) => item.id === Number(warehouseId));
    const balance = useQuery({
        queryKey: inventoryKeys.balances({
            warehouse_id: Number(warehouseId),
            product_variant_id: Number(variantId),
        }),
        queryFn: () =>
            inventoryApi.balances({
                warehouse_id: Number(warehouseId),
                product_variant_id: Number(variantId),
            }),
        enabled: Boolean(warehouseId && variantId) && mode !== "history",
    });
    const movementFilters = {
        warehouse_id: Number(warehouseId),
        product_variant_id: Number(variantId),
        page: mode === "history" ? historyPage : 1,
        per_page: mode === "history" ? 20 : 1,
    };
    const movements = useQuery({
        queryKey: inventoryKeys.movements(movementFilters),
        queryFn: () => inventoryApi.movements(movementFilters),
        enabled: Boolean(warehouseId && variantId) && (mode === "opening" || mode === "history"),
    });
    const openingLocked = mode === "opening" && (movements.data?.total ?? 0) > 0;
    const currentBalance = balance.data?.data[0];
    const current = currentBalance?.on_hand_quantity ?? readonlyRow?.on_hand_quantity ?? "0.000";
    const reserved = currentBalance?.reserved_quantity ?? readonlyRow?.reserved_quantity ?? "0.000";
    const validQuantity =
        mode === "opening"
            ? isNonNegativeProductQuantity(quantity)
            : mode === "adjustment"
              ? isSignedProductQuantity(quantity)
              : isPositiveProductQuantity(quantity);
    const before = wholeStock(current);
    const reservedValue = wholeStock(reserved);
    const delta = validQuantity ? BigInt(quantity) : null;
    const projected = before !== null && delta !== null ? before + delta : null;
    const stockError =
        projected !== null &&
        (projected < 0n || (reservedValue !== null && projected < reservedValue))
            ? projected < 0n
                ? "Số lượng điều chỉnh làm tồn kho nhỏ hơn 0."
                : "Tồn sau điều chỉnh không thể nhỏ hơn số lượng đã giữ."
            : "";
    const reasonOptions = mode === "adjustment" ? adjustmentReasons : inboundReasons;
    const reasonLabel = reasonOptions.find(([code]) => code === reasonCode)?.[1] ?? reasonCode;
    const productName = readonlyRow?.product.name ?? selectedProduct?.name ?? "—";
    const sku = readonlyRow?.variant.sku ?? selectedVariant?.sku ?? "—";
    const variantName = readonlyRow?.variant.variant_name ?? selectedVariant?.variant_name ?? "—";
    const unit = readonlyRow?.unit.symbol ?? selectedVariant?.unit?.symbol ?? "";

    const operation = useMutation({
        mutationFn: (body: InventoryOperation) =>
            mode === "opening"
                ? inventoryApi.opening(body)
                : mode === "receipt"
                  ? inventoryApi.receipt(body)
                  : inventoryApi.adjustment(body),
    });

    const review = () => {
        if (
            !warehouseId ||
            !variantId ||
            !validQuantity ||
            stockError ||
            balance.isPending ||
            balance.isError ||
            (mode === "opening" && (openingLocked || movements.isPending || movements.isError))
        ) {
            setErrors({ quantity: stockError || "Chọn Kho, SKU và nhập số lượng hợp lệ." });
            return;
        }
        if (mode !== "opening" && !reasonCode) {
            setErrors({ reason_code: "Vui lòng chọn lý do." });
            return;
        }
        if ((mode === "opening" || mode === "adjustment") && !note.trim()) {
            setErrors({ reason_detail: "Vui lòng nhập ghi chú." });
            return;
        }
        setErrors({});
        setConfirm(true);
    };

    const submit = async () => {
        if (
            submitting.current ||
            operation.isPending ||
            !validQuantity ||
            projected === null ||
            stockError ||
            openingLocked ||
            balance.isError ||
            (mode === "opening" && movements.isError)
        )
            return;
        submitting.current = true;
        const body: InventoryOperation = {
            warehouse_id: Number(warehouseId),
            product_variant_id: Number(variantId),
            quantity,
            operation_key: operationKey,
            reason_detail: note.trim(),
            ...(reasonCode ? { reason_code: reasonCode } : {}),
            ...(reference.trim()
                ? ({ reference_type: "MANUAL", reference_id: reference.trim() } as const)
                : {}),
        };
        try {
            await operation.mutateAsync(body);
            setConfirm(false);
            await onSuccess();
            toast.success(`Đã ${titles[mode].toLowerCase()} và cập nhật tồn kho.`);
            onClose();
        } catch (error) {
            setConfirm(false);
            setErrors(firstFieldErrors(error));
            toast.error(errorMessage(error));
        } finally {
            submitting.current = false;
        }
    };

    return (
        <>
            <Sheet
                open
                onOpenChange={(open) => {
                    if (!open && !confirm && !operation.isPending) onClose();
                }}
            >
                <SheetContent
                    side="right"
                    overlayClassName="bg-black/35"
                    className="flex h-dvh w-full max-w-none flex-col p-0 sm:w-[70vw] sm:max-w-none lg:w-[520px]"
                >
                    <SheetHeader className="shrink-0 border-b px-5 py-5 pr-12 text-left">
                        <SheetTitle className="text-lg text-primary">{titles[mode]}</SheetTitle>
                        <SheetDescription>
                            {mode === "receipt"
                                ? "Dùng khi hàng vào kho không thông qua đơn mua hàng."
                                : mode === "opening"
                                  ? "Chỉ dùng một lần trước khi Kho và SKU phát sinh biến động."
                                  : mode === "adjustment"
                                    ? "Ghi chênh lệch giữa tồn thực tế và hệ thống."
                                    : "Biến động thực tế của SKU tại kho đã chọn."}
                        </SheetDescription>
                    </SheetHeader>
                    <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5">
                        {readonlyRow ? (
                            <div className="grid gap-2 rounded-lg border bg-muted/30 p-4 text-sm">
                                <strong>{readonlyRow.product.name}</strong>
                                <span>
                                    {readonlyRow.variant.sku} ·{" "}
                                    {readonlyRow.variant.variant_name || "Biến thể mặc định"}
                                </span>
                                <span>
                                    {readonlyRow.warehouse.name} · {readonlyRow.unit.symbol}
                                </span>
                                <span>
                                    Tồn kho: <strong>{formatProductQuantity(current)}</strong> · Đã
                                    giữ: {formatProductQuantity(reserved)} · Khả dụng:{" "}
                                    {formatProductQuantity(
                                        currentBalance?.available_quantity ??
                                            readonlyRow.available_quantity,
                                    )}
                                </span>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                <label className="block text-sm">
                                    Kho *
                                    <select
                                        className={fieldClass}
                                        value={warehouseId}
                                        onChange={(event) => {
                                            setWarehouseId(event.target.value);
                                            setOperationKey(crypto.randomUUID());
                                        }}
                                    >
                                        <option value="">Chọn kho</option>
                                        {warehouses
                                            .filter((item) => item.status === "active")
                                            .map((item) => (
                                                <option key={item.id} value={item.id}>
                                                    {item.name}
                                                </option>
                                            ))}
                                    </select>
                                </label>
                                <label className="block text-sm">
                                    Tìm sản phẩm
                                    <input
                                        className={fieldClass}
                                        value={productSearch}
                                        placeholder="Tên, mã hoặc SKU"
                                        onChange={(event) => {
                                            setProductSearch(event.target.value);
                                            setProductId("");
                                            setVariantId("");
                                        }}
                                    />
                                </label>
                                <label className="block text-sm">
                                    Sản phẩm *
                                    <select
                                        className={fieldClass}
                                        value={productId}
                                        onChange={(event) => {
                                            setProductId(event.target.value);
                                            setVariantId("");
                                        }}
                                    >
                                        <option value="">Chọn sản phẩm</option>
                                        {products.data?.data
                                            .filter((item) => item.status === "active")
                                            .map((item) => (
                                                <option key={item.id} value={item.id}>
                                                    {item.name}
                                                </option>
                                            ))}
                                    </select>
                                </label>
                                <label className="block text-sm">
                                    SKU *
                                    <select
                                        className={fieldClass}
                                        value={variantId}
                                        onChange={(event) => {
                                            setVariantId(event.target.value);
                                            setOperationKey(crypto.randomUUID());
                                        }}
                                    >
                                        <option value="">Chọn SKU</option>
                                        {variants.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.sku} · {item.variant_name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                {variantId && (
                                    <div className="rounded-lg border bg-muted/30 p-3 text-sm">
                                        {selectedWarehouse?.name} · {sku} · {unit}
                                        <br />
                                        Tồn hiện tại:{" "}
                                        {balance.isFetching
                                            ? "Đang tải…"
                                            : formatProductQuantity(current)}
                                    </div>
                                )}
                            </div>
                        )}
                        {mode === "history" ? (
                            <div className="space-y-3">
                                {movements.isPending ? (
                                    <LoadingState />
                                ) : movements.isError ? (
                                    <ErrorState
                                        message={errorMessage(movements.error)}
                                        retry={() => void movements.refetch()}
                                    />
                                ) : movements.data.data.length ? (
                                    <>
                                        {movements.data.data.map((item) => (
                                            <div
                                                key={item.id}
                                                className="space-y-1 rounded-lg border p-3 text-sm"
                                            >
                                                <div className="flex justify-between gap-2">
                                                    <strong>
                                                        {item.movement_type === "GOODS_RECEIPT" &&
                                                        (item.reference_type === "MANUAL" ||
                                                            item.reason_code !== null)
                                                            ? "Nhập kho thủ công"
                                                            : movementNames[item.movement_type]}
                                                    </strong>
                                                    <span>
                                                        {new Date(item.occurred_at).toLocaleString(
                                                            "vi-VN",
                                                        )}
                                                    </span>
                                                </div>
                                                <p className="tabular-nums">
                                                    {formatProductQuantity(item.quantity)} ·{" "}
                                                    {formatProductQuantity(
                                                        item.before_on_hand_quantity,
                                                    )}{" "}
                                                    →{" "}
                                                    {formatProductQuantity(
                                                        item.after_on_hand_quantity,
                                                    )}
                                                </p>
                                                {(item.reason_code || item.reason_detail) && (
                                                    <p>
                                                        Lý do: {stockReasonLabel(item.reason_code)}
                                                        {item.reason_detail
                                                            ? ` · ${item.reason_detail}`
                                                            : ""}
                                                    </p>
                                                )}
                                                {item.reference_id && (
                                                    <p>
                                                        Tham chiếu: {item.reference_type} ·{" "}
                                                        {item.reference_id}
                                                    </p>
                                                )}
                                                <p className="text-muted-foreground">
                                                    Người thực hiện:{" "}
                                                    {item.actor?.name ?? item.source}
                                                </p>
                                            </div>
                                        ))}
                                        <Pagination
                                            current={movements.data.current_page}
                                            last={movements.data.last_page}
                                            onPage={setHistoryPage}
                                        />
                                    </>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Chưa có biến động kho.
                                    </p>
                                )}
                            </div>
                        ) : (
                            <form
                                id="inventory-drawer-form"
                                className="space-y-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    review();
                                }}
                            >
                                {mode === "opening" && movements.isError && (
                                    <ErrorState
                                        message={errorMessage(movements.error)}
                                        retry={() => void movements.refetch()}
                                    />
                                )}
                                {balance.isError && (
                                    <ErrorState
                                        message={errorMessage(balance.error)}
                                        retry={() => void balance.refetch()}
                                    />
                                )}
                                {mode === "opening" && openingLocked && (
                                    <div
                                        role="alert"
                                        className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
                                    >
                                        SKU này đã phát sinh giao dịch kho nên không thể thiết lập
                                        tồn đầu kỳ. Nếu số lượng hiện tại không chính xác, hãy sử
                                        dụng Điều chỉnh tồn.
                                        <button
                                            type="button"
                                            className="mt-2 block underline"
                                            onClick={() => {
                                                setMode("adjustment");
                                                setQuantity("");
                                                setErrors({});
                                            }}
                                        >
                                            Điều chỉnh tồn
                                        </button>
                                    </div>
                                )}
                                <label className="block text-sm">
                                    {mode === "opening"
                                        ? "Số lượng tồn đầu kỳ"
                                        : mode === "receipt"
                                          ? "Số lượng nhập"
                                          : "Số lượng điều chỉnh (+/-)"}{" "}
                                    *
                                    <input
                                        type="number"
                                        step="1"
                                        min={
                                            mode === "adjustment"
                                                ? undefined
                                                : mode === "opening"
                                                  ? 0
                                                  : 1
                                        }
                                        className={fieldClass}
                                        value={quantity}
                                        onChange={(event) => {
                                            setQuantity(event.target.value);
                                            setOperationKey(crypto.randomUUID());
                                        }}
                                        placeholder={
                                            mode === "adjustment"
                                                ? "-2 hoặc 2"
                                                : mode === "opening"
                                                  ? "0"
                                                  : "1"
                                        }
                                    />
                                </label>
                                {(errors["quantity"] || stockError) && (
                                    <p role="alert" className="text-sm text-red-700">
                                        {errors["quantity"] || stockError}
                                    </p>
                                )}
                                {mode !== "opening" && (
                                    <label className="block text-sm">
                                        Lý do *
                                        <select
                                            className={fieldClass}
                                            value={reasonCode}
                                            onChange={(event) => {
                                                setReasonCode(event.target.value);
                                                setOperationKey(crypto.randomUUID());
                                            }}
                                        >
                                            <option value="">Chọn lý do</option>
                                            {reasonOptions.map(([code, label]) => (
                                                <option key={code} value={code}>
                                                    {label}
                                                </option>
                                            ))}
                                        </select>
                                        {errors["reason_code"] && (
                                            <span className="text-red-700">
                                                {errors["reason_code"]}
                                            </span>
                                        )}
                                    </label>
                                )}
                                {mode !== "opening" && (
                                    <label className="block text-sm">
                                        Mã tham chiếu
                                        <input
                                            className={fieldClass}
                                            value={reference}
                                            onChange={(event) => {
                                                setReference(event.target.value);
                                                setOperationKey(crypto.randomUUID());
                                            }}
                                            placeholder="Phiếu nội bộ hoặc tài liệu ngoài hệ thống"
                                        />
                                    </label>
                                )}
                                {mode === "opening" && (
                                    <p className="text-sm">
                                        Ngày ghi nhận: {new Date().toLocaleDateString("vi-VN")}{" "}
                                        (theo thời điểm xác nhận)
                                    </p>
                                )}
                                <label className="block text-sm">
                                    Ghi chú {mode !== "receipt" && "*"}
                                    <textarea
                                        className={fieldClass}
                                        value={note}
                                        onChange={(event) => {
                                            setNote(event.target.value);
                                            setOperationKey(crypto.randomUUID());
                                        }}
                                        rows={3}
                                    />
                                    {errors["reason_detail"] && (
                                        <span className="text-red-700">
                                            {errors["reason_detail"]}
                                        </span>
                                    )}
                                </label>
                                {variantId && (
                                    <div className="space-y-1 rounded-lg border bg-muted/30 p-4 text-sm tabular-nums">
                                        <p>
                                            Tồn hiện tại: {formatProductQuantity(current)} {unit}
                                        </p>
                                        <p>
                                            {mode === "opening"
                                                ? "Tồn đầu kỳ"
                                                : mode === "receipt"
                                                  ? "Nhập thêm"
                                                  : "Điều chỉnh"}
                                            :{" "}
                                            {delta === null
                                                ? "—"
                                                : `${delta > 0n && mode !== "opening" ? "+" : ""}${delta}`}{" "}
                                            {unit}
                                        </p>
                                        <p className="border-t pt-2 font-semibold">
                                            Tồn sau thao tác:{" "}
                                            {projected === null ? "—" : String(projected)} {unit}
                                        </p>
                                    </div>
                                )}
                            </form>
                        )}
                    </div>
                    <div className="flex shrink-0 justify-end gap-2 border-t bg-background px-5 py-4">
                        <button type="button" className={secondaryButtonClass} onClick={onClose}>
                            Hủy
                        </button>
                        {mode !== "history" && (
                            <button
                                type="submit"
                                form="inventory-drawer-form"
                                className={buttonClass}
                                disabled={
                                    !warehouseId ||
                                    !variantId ||
                                    !validQuantity ||
                                    Boolean(stockError) ||
                                    openingLocked ||
                                    balance.isPending ||
                                    balance.isError ||
                                    (mode === "opening" &&
                                        (movements.isPending || movements.isError))
                                }
                            >
                                Xem và xác nhận
                            </button>
                        )}
                    </div>
                </SheetContent>
            </Sheet>
            <Dialog
                open={confirm}
                onOpenChange={(open) => {
                    if (!open && !operation.isPending) setConfirm(false);
                }}
            >
                <DialogContent className="w-[min(94vw,480px)]">
                    <DialogHeader>
                        <DialogTitle>Xác nhận {titles[mode].toLowerCase()}</DialogTitle>
                        <DialogDescription>
                            Thao tác này sẽ được ghi vào lịch sử biến động kho.
                        </DialogDescription>
                    </DialogHeader>
                    <dl className="grid grid-cols-2 gap-2 text-sm">
                        <dt>Sản phẩm</dt>
                        <dd>{productName}</dd>
                        <dt>SKU</dt>
                        <dd>
                            {sku} · {variantName}
                        </dd>
                        <dt>Kho</dt>
                        <dd>{selectedWarehouse?.name ?? readonlyRow?.warehouse.name}</dd>
                        <dt>Tồn hiện tại</dt>
                        <dd>
                            {formatProductQuantity(current)} {unit}
                        </dd>
                        <dt>Thay đổi</dt>
                        <dd>
                            {quantity} {unit}
                        </dd>
                        <dt className="border-t pt-2">Tồn sau</dt>
                        <dd className="border-t pt-2 font-semibold">
                            {projected === null ? "—" : String(projected)} {unit}
                        </dd>
                        <dt>Lý do</dt>
                        <dd>{reasonLabel || "—"}</dd>
                        {note && (
                            <>
                                <dt>Ghi chú</dt>
                                <dd>{note}</dd>
                            </>
                        )}
                    </dl>
                    <DialogFooter className="gap-2">
                        <button
                            type="button"
                            className={secondaryButtonClass}
                            disabled={operation.isPending}
                            onClick={() => setConfirm(false)}
                        >
                            Quay lại
                        </button>
                        <button
                            type="button"
                            className={buttonClass}
                            disabled={operation.isPending}
                            onClick={() => void submit()}
                        >
                            {operation.isPending ? "Đang ghi…" : "Xác nhận"}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
