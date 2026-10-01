import { useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from "@/components/ui/sheet";
import { errorMessage } from "@/services/api";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    formatProductQuantity,
    isNonNegativeProductQuantity,
    isPositiveProductQuantity,
} from "@/lib/productQuantity";
import { salesOrderApi, salesOrderKeys } from "@/services/salesOrderApi";
import type { OrderReturn, SalesOrder } from "@/types/salesOrder";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { buttonClass, fieldClass, secondaryButtonClass } from "./ProductAdminShared";

const money = (value: string) => `${new Intl.NumberFormat("vi-VN").format(Number(value))} ₫`;
const dispositionReasons: Record<string, string> = {
    damaged: "Hư hỏng",
    used: "Đã sử dụng",
    expired: "Hết hạn",
    damaged_packaging: "Hỏng bao bì",
    missing_parts: "Thiếu bộ phận",
    unsellable: "Không thể bán lại",
    destroyed: "Đã tiêu hủy",
    other: "Khác",
};

export function ReturnRefundSection({ order }: { order: SalesOrder }) {
    const client = useQueryClient();
    const refunds = useQuery({
        queryKey: ["order-refunds", order.id],
        queryFn: () => salesOrderApi.refunds(order.id),
    });
    const returns = useQuery({
        queryKey: ["order-returns", order.id],
        queryFn: () => salesOrderApi.returns(order.id),
    });
    const returnable = useQuery({
        queryKey: ["order-returnable", order.id],
        queryFn: () => salesOrderApi.returnableItems(order.id),
    });
    const [mode, setMode] = useState<"refund" | "return" | null>(null);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const actionPending = useRef(false);
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const [amount, setAmount] = useState("");
    const [method, setMethod] = useState<
        "cash" | "bank_transfer" | "other_manual" | "dealer_wallet"
    >("bank_transfer");
    const [decision, setDecision] = useState<{
        entry: OrderReturn;
        action: "approve" | "reject" | "receive";
    } | null>(null);
    const [rejectionReason, setRejectionReason] = useState("");
    const [reason, setReason] = useState<
        "return" | "order_cancel" | "price_adjustment" | "service_recovery" | "other"
    >("return");
    const [returnId, setReturnId] = useState("");
    const [reference, setReference] = useState("");
    const [note, setNote] = useState("");
    const [returnReason, setReturnReason] = useState("");
    const [quantities, setQuantities] = useState<Record<number, string>>({});
    const [restocks, setRestocks] = useState<Record<number, string>>({});
    const [returnMode, setReturnMode] = useState<"immediate" | "pending_inspection">("immediate");
    const [dispositionReasonsByItem, setDispositionReasonsByItem] = useState<
        Record<number, string>
    >({});
    const [dispositionNotes, setDispositionNotes] = useState<Record<number, string>>({});
    const [inspectionReturn, setInspectionReturn] = useState<OrderReturn | null>(null);
    const [inspectionRestocks, setInspectionRestocks] = useState<Record<number, string>>({});
    const [inspectionReasons, setInspectionReasons] = useState<Record<number, string>>({});
    const [inspectionNotes, setInspectionNotes] = useState<Record<number, string>>({});
    const [retryKey, setRetryKey] = useState<string | null>(null);

    const selectedLines = (returnable.data?.data ?? [])
        .filter((item) => isPositiveProductQuantity(quantities[item.item_id] ?? ""))
        .map((item) => ({
            item_id: item.item_id,
            quantity: quantities[item.item_id]!,
            restock_quantity:
                returnMode === "pending_inspection"
                    ? quantities[item.item_id]!
                    : (restocks[item.item_id] ?? quantities[item.item_id]!),
            ...(dispositionReasonsByItem[item.item_id] && {
                non_restock_reason_code: dispositionReasonsByItem[item.item_id],
            }),
            ...(dispositionNotes[item.item_id]?.trim() && {
                non_restock_note: dispositionNotes[item.item_id]!.trim(),
            }),
        }));
    const linkedReturn = (returns.data?.data ?? []).find((entry) => entry.id === Number(returnId));
    const linkedRefundable = linkedReturn
        ? Math.max(0, linkedReturn.return_value - linkedReturn.refunded_amount)
        : 0;
    const refundValid =
        /^\d+(?:\.\d{1,2})?$/.test(amount) &&
        Number(amount) > 0 &&
        Number(amount) <= Number(order.refundable_amount) &&
        (reason !== "return" || (Boolean(returnId) && Number(amount) <= linkedRefundable));
    const returnValid =
        returnReason.trim().length > 0 &&
        Object.values(quantities).every(
            (value) => value === "" || isPositiveProductQuantity(value),
        ) &&
        (returnMode === "pending_inspection" ||
            Object.values(restocks).every(isNonNegativeProductQuantity)) &&
        selectedLines.length > 0 &&
        selectedLines.every((line) => {
            const item = returnable.data?.data.find(
                (candidate) => candidate.item_id === line.item_id,
            );
            return (
                item &&
                isPositiveProductQuantity(line.quantity) &&
                (returnMode === "pending_inspection" ||
                    isNonNegativeProductQuantity(line.restock_quantity)) &&
                Number(line.quantity) <= Number(item.returnable_quantity) &&
                Number(line.restock_quantity) >= 0 &&
                (returnMode === "pending_inspection" ||
                    (Number(line.restock_quantity) <= Number(line.quantity) &&
                        (Number(line.restock_quantity) === Number(line.quantity) ||
                            (Boolean(line.non_restock_reason_code) &&
                                (line.non_restock_reason_code !== "other" ||
                                    Boolean(line.non_restock_note))))))
            );
        });
    const inspectionValid =
        inspectionReturn !== null &&
        inspectionReturn.items.every((item) => {
            const restock = inspectionRestocks[item.id] ?? item.quantity;
            const reason = inspectionReasons[item.id];
            return (
                isNonNegativeProductQuantity(restock) &&
                Number(restock) <= Number(item.quantity) &&
                (Number(restock) === Number(item.quantity) ||
                    (Boolean(reason) &&
                        (reason !== "other" || Boolean(inspectionNotes[item.id]?.trim()))))
            );
        });

    function openInspection(entry: OrderReturn): void {
        setInspectionReturn(entry);
        setInspectionRestocks(
            Object.fromEntries(entry.items.map((item) => [item.id, item.quantity])),
        );
        setInspectionReasons({});
        setInspectionNotes({});
        setRetryKey(null);
        setError("");
    }

    async function submitDecision(): Promise<void> {
        if (!decision || busy || (decision.action === "reject" && !rejectionReason.trim())) return;
        setBusy(true);
        setError("");
        try {
            if (decision.action === "approve") await salesOrderApi.approveReturn(decision.entry.id);
            if (decision.action === "reject")
                await salesOrderApi.rejectReturn(decision.entry.id, rejectionReason.trim());
            if (decision.action === "receive") await salesOrderApi.receiveReturn(decision.entry.id);
            toast.success(
                decision.action === "approve"
                    ? "Đã duyệt yêu cầu."
                    : decision.action === "reject"
                      ? "Đã từ chối yêu cầu."
                      : "Đã ghi nhận nhận hàng.",
            );
            setDecision(null);
            setRejectionReason("");
            await Promise.all([
                client.invalidateQueries({ queryKey: ["order-returns", order.id] }),
                client.invalidateQueries({ queryKey: ["order-returnable", order.id] }),
                client.invalidateQueries({ queryKey: salesOrderKeys.detail(order.id) }),
            ]);
        } catch (cause) {
            setError(errorMessage(cause));
            toast.error(errorMessage(cause));
        } finally {
            setBusy(false);
        }
    }

    async function submit(): Promise<void> {
        if ((!mode && !inspectionReturn) || actionPending.current) return;
        actionPending.current = true;
        setBusy(true);
        setError("");
        const key = retryKey ?? crypto.randomUUID();
        setRetryKey(key);
        try {
            if (inspectionReturn) {
                const result = await salesOrderApi.processReturn(inspectionReturn.id, {
                    operation_key: key,
                    items: inspectionReturn.items.map((item) => ({
                        return_item_id: item.id,
                        restock_quantity: inspectionRestocks[item.id] ?? item.quantity,
                        ...(inspectionReasons[item.id] && {
                            non_restock_reason_code: inspectionReasons[item.id],
                        }),
                        ...(inspectionNotes[item.id]?.trim() && {
                            non_restock_note: inspectionNotes[item.id]!.trim(),
                        }),
                    })),
                });
                setSuccess(`Đã xử lý phiếu ${result.data.return_code}.`);
                setInspectionReturn(null);
            } else if (mode === "refund") {
                const response = await salesOrderApi.completeRefund(order.id, {
                    operation_key: key,
                    amount,
                    refund_method: method,
                    reason,
                    ...(returnId && { return_id: Number(returnId) }),
                    ...(reference.trim() && { external_reference: reference.trim() }),
                    ...(note.trim() && { note: note.trim() }),
                });
                setSuccess(`Đã hoàn tiền. Mã: ${response.data.refund.refund_code}`);
                setAmount("");
                setReference("");
            } else {
                const response = await salesOrderApi.completeReturn(order.id, {
                    operation_key: key,
                    reason: returnReason.trim(),
                    processing_mode: returnMode,
                    ...(note.trim() && { note: note.trim() }),
                    items: selectedLines,
                });
                const lines = response.data.items
                    .map(
                        (item) =>
                            `${item.sku}: trả ${formatProductQuantity(item.quantity)}, nhập kho ${formatProductQuantity(item.restock_quantity)}, không nhập kho ${formatProductQuantity(item.non_restock_quantity)}`,
                    )
                    .join("; ");
                setSuccess(
                    returnMode === "pending_inspection"
                        ? `Đã ghi nhận ${response.data.return_code}, đang chờ kiểm tra. Tồn kho chưa thay đổi.`
                        : `Đã ghi nhận ${response.data.return_code} tại kho ${order.warehouse.code}. ${lines}. Chưa hoàn tiền.`,
                );
                setQuantities({});
                setRestocks({});
                setDispositionReasonsByItem({});
                setDispositionNotes({});
            }
            setMode(null);
            setConfirming(false);
            toast.success(
                inspectionReturn
                    ? "Đã xử lý hàng trả."
                    : mode === "refund"
                      ? "Hoàn tiền thành công."
                      : "Ghi nhận trả hàng thành công.",
            );
            setRetryKey(null);
            await Promise.all([
                client.invalidateQueries({ queryKey: salesOrderKeys.detail(order.id) }),
                client.invalidateQueries({ queryKey: ["order-refunds", order.id] }),
                client.invalidateQueries({ queryKey: ["order-returns", order.id] }),
                client.invalidateQueries({ queryKey: ["order-returnable", order.id] }),
                client.invalidateQueries({ queryKey: ["sales-orders"] }),
            ]);
        } catch (cause) {
            setError(errorMessage(cause));
            toast.error(errorMessage(cause));
        } finally {
            actionPending.current = false;
            setBusy(false);
        }
    }

    return (
        <section className="space-y-5 rounded-xl border bg-card p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl text-primary">Trả hàng & hoàn tiền</h2>
                    <p className="text-sm text-muted-foreground">
                        {Number(order.paid_amount) === 0
                            ? "Đơn chưa thu tiền nên hiện chưa có khoản để hoàn."
                            : `Đã hoàn ${money(order.refunded_amount)} trên ${money(order.paid_amount)} đã thu.`}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {Number(order.refundable_amount) > 0 && (
                        <button
                            className={buttonClass}
                            onClick={() => {
                                setMode("refund");
                                setMethod(
                                    order.payment_method === "dealer_wallet"
                                        ? "dealer_wallet"
                                        : "bank_transfer",
                                );
                                setError("");
                            }}
                        >
                            Hoàn tiền
                        </button>
                    )}
                    {(returnable.data?.data ?? []).some(
                        (item) => Number(item.returnable_quantity) > 0,
                    ) && (
                        <button
                            className={secondaryButtonClass}
                            onClick={() => {
                                setMode("return");
                                setError("");
                            }}
                        >
                            Tạo phiếu trả hàng
                        </button>
                    )}
                </div>
            </div>
            {(returns.data?.data.length ?? 0) === 0 && !returns.isPending && !returns.isError && (
                <p className="text-sm text-muted-foreground">Chưa có yêu cầu trả hàng.</p>
            )}
            {(returns.data?.data.length ?? 0) > 0 && (
                <p className="text-sm text-muted-foreground">
                    {returns.data!.data.length} yêu cầu trả hàng ·{" "}
                    {
                        returns.data!.data.filter((entry) =>
                            ["requested", "approved", "pending"].includes(entry.status),
                        ).length
                    }{" "}
                    cần xử lý
                </p>
            )}
            <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-5">
                <p>
                    Tổng đã thu
                    <br />
                    <strong>{money(order.paid_amount)}</strong>
                </p>
                <p>
                    Đã hoàn
                    <br />
                    <strong>{money(order.refunded_amount)}</strong>
                </p>
                <p>
                    Còn có thể hoàn
                    <br />
                    <strong>{money(order.refundable_amount)}</strong>
                </p>
                <p>
                    Thực thu sau hoàn
                    <br />
                    <strong>{money(order.net_settled_amount)}</strong>
                </p>
                <p>
                    Còn phải thu theo đơn
                    <br />
                    <strong>{money(order.outstanding_amount)}</strong>
                </p>
                <p>
                    Yêu cầu trả hàng
                    <br />
                    <strong>{returns.data?.data.length ?? 0}</strong>
                </p>
            </div>
            {success && (
                <p role="status" className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">
                    {success}
                </p>
            )}
            {error && (
                <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-800">
                    {error}
                </p>
            )}
            {(refunds.isError || returns.isError || returnable.isError) && (
                <p role="alert" className="text-sm text-red-700">
                    Không thể tải dữ liệu trả hàng hoặc hoàn tiền.
                </p>
            )}
            {((refunds.data?.data.refunds.length ?? 0) > 0 ||
                (returns.data?.data.length ?? 0) > 0) && (
                <button
                    type="button"
                    className={secondaryButtonClass}
                    onClick={() => setHistoryOpen(true)}
                >
                    Xem phiếu trả hàng & lịch sử hoàn tiền
                </button>
            )}
            <Dialog
                open={mode !== null && !confirming}
                onOpenChange={(open) => {
                    if (!open && !busy) setMode(null);
                }}
            >
                <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {mode === "refund" ? "Hoàn tiền" : "Tạo phiếu trả hàng"}
                        </DialogTitle>
                        <DialogDescription>
                            Kiểm tra thông tin trước khi xác nhận.
                        </DialogDescription>
                    </DialogHeader>
                    {mode === "refund" && (
                        <div className="grid gap-3 border-t pt-4 sm:grid-cols-2">
                            <label className="grid gap-1 text-sm">
                                Số tiền hoàn
                                <input
                                    className={fieldClass}
                                    inputMode="decimal"
                                    value={amount}
                                    onChange={(event) => {
                                        setAmount(event.target.value);
                                        setRetryKey(null);
                                    }}
                                    placeholder={order.refundable_amount}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Phương thức
                                <select
                                    className={fieldClass}
                                    value={method}
                                    disabled={order.payment_method === "dealer_wallet"}
                                    onChange={(event) => {
                                        setMethod(event.target.value as typeof method);
                                        setRetryKey(null);
                                    }}
                                >
                                    <option value="bank_transfer">Chuyển khoản</option>
                                    <option value="cash">Tiền mặt</option>
                                    <option value="other_manual">Thủ công khác</option>
                                    {order.sales_channel === "dealer" && (
                                        <option value="dealer_wallet">Ví đại lý</option>
                                    )}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Lý do
                                <select
                                    className={fieldClass}
                                    value={reason}
                                    onChange={(event) => {
                                        setReason(event.target.value as typeof reason);
                                        setRetryKey(null);
                                    }}
                                >
                                    <option value="return">Trả hàng</option>
                                    <option value="order_cancel">Hủy đơn</option>
                                    <option value="price_adjustment">Điều chỉnh giá</option>
                                    <option value="service_recovery">Chăm sóc khách hàng</option>
                                    <option value="other">Khác</option>
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Phiếu trả hàng liên kết{" "}
                                {reason === "return" ? "*" : "(không bắt buộc)"}
                                <select
                                    className={fieldClass}
                                    value={returnId}
                                    onChange={(event) => {
                                        setReturnId(event.target.value);
                                        setRetryKey(null);
                                    }}
                                >
                                    <option value="">Không liên kết</option>
                                    {(returns.data?.data ?? [])
                                        .filter((entry) => entry.status === "completed")
                                        .map((entry) => (
                                            <option key={entry.id} value={entry.id}>
                                                {entry.return_code}
                                            </option>
                                        ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Mã giao dịch hoàn
                                <input
                                    className={fieldClass}
                                    value={reference}
                                    onChange={(event) => {
                                        setReference(event.target.value);
                                        setRetryKey(null);
                                    }}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Ghi chú nội bộ
                                <input
                                    className={fieldClass}
                                    value={note}
                                    onChange={(event) => {
                                        setNote(event.target.value);
                                        setRetryKey(null);
                                    }}
                                />
                            </label>
                            <div className="flex gap-2 sm:col-span-2">
                                <button
                                    className={buttonClass}
                                    disabled={!refundValid || busy}
                                    onClick={() => setConfirming(true)}
                                >
                                    Xác nhận hoàn tiền
                                </button>
                                <button
                                    className={secondaryButtonClass}
                                    onClick={() => setMode(null)}
                                >
                                    Đóng
                                </button>
                            </div>
                        </div>
                    )}
                    {mode === "return" && (
                        <div className="space-y-3 border-t pt-4">
                            <fieldset className="flex flex-wrap gap-4 text-sm">
                                <legend className="mb-2 font-medium">Cách xử lý hàng trả</legend>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        checked={returnMode === "immediate"}
                                        onChange={() => {
                                            setReturnMode("immediate");
                                            setRetryKey(null);
                                        }}
                                    />{" "}
                                    Kiểm tra và xử lý ngay
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        checked={returnMode === "pending_inspection"}
                                        onChange={() => {
                                            setReturnMode("pending_inspection");
                                            setRetryKey(null);
                                        }}
                                    />{" "}
                                    Chờ kiểm tra
                                </label>
                            </fieldset>
                            <label className="grid gap-1 text-sm">
                                Lý do trả hàng
                                <input
                                    className={fieldClass}
                                    value={returnReason}
                                    onChange={(event) => {
                                        setReturnReason(event.target.value);
                                        setRetryKey(null);
                                    }}
                                />
                            </label>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[640px] text-left text-sm">
                                    <thead>
                                        <tr>
                                            <th>Sản phẩm / SKU</th>
                                            <th>Đã giao</th>
                                            <th>Đã trả</th>
                                            <th>Còn trả được</th>
                                            <th>Số trả</th>
                                            {returnMode === "immediate" && (
                                                <>
                                                    <th>Nhập lại kho</th>
                                                    <th>Lý do không nhập kho</th>
                                                </>
                                            )}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(returnable.data?.data ?? [])
                                            .filter((item) => Number(item.returnable_quantity) > 0)
                                            .map((item) => (
                                                <tr key={item.item_id} className="border-t">
                                                    <td className="py-2">
                                                        {item.product_name}
                                                        <br />
                                                        <span className="text-muted-foreground">
                                                            {item.sku}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        {formatProductQuantity(
                                                            item.fulfilled_quantity,
                                                        )}
                                                    </td>
                                                    <td>
                                                        {formatProductQuantity(
                                                            item.returned_quantity,
                                                        )}
                                                    </td>
                                                    <td>
                                                        {formatProductQuantity(
                                                            item.returnable_quantity,
                                                        )}{" "}
                                                        {item.unit_code}
                                                    </td>
                                                    <td>
                                                        <input
                                                            className={fieldClass}
                                                            type="number"
                                                            min={1}
                                                            step={1}
                                                            aria-label={`Số trả ${item.sku}`}
                                                            value={quantities[item.item_id] ?? ""}
                                                            onChange={(event) => {
                                                                setQuantities((current) => ({
                                                                    ...current,
                                                                    [item.item_id]:
                                                                        event.target.value,
                                                                }));
                                                                setRestocks((current) => ({
                                                                    ...current,
                                                                    [item.item_id]:
                                                                        event.target.value,
                                                                }));
                                                                setRetryKey(null);
                                                            }}
                                                        />
                                                    </td>
                                                    {returnMode === "immediate" && (
                                                        <td>
                                                            <input
                                                                className={fieldClass}
                                                                type="number"
                                                                min={0}
                                                                step={1}
                                                                aria-label={`Nhập lại kho ${item.sku}`}
                                                                value={
                                                                    restocks[item.item_id] ??
                                                                    quantities[item.item_id] ??
                                                                    ""
                                                                }
                                                                onChange={(event) => {
                                                                    setRestocks((current) => ({
                                                                        ...current,
                                                                        [item.item_id]:
                                                                            event.target.value,
                                                                    }));
                                                                    setRetryKey(null);
                                                                }}
                                                            />
                                                        </td>
                                                    )}
                                                    {returnMode === "immediate" && (
                                                        <td className="min-w-48 py-2">
                                                            {Number(quantities[item.item_id] || 0) >
                                                                Number(
                                                                    restocks[item.item_id] ??
                                                                        quantities[item.item_id] ??
                                                                        0,
                                                                ) && (
                                                                <div className="grid gap-1">
                                                                    <select
                                                                        className={fieldClass}
                                                                        aria-label={`Lý do không nhập kho ${item.sku}`}
                                                                        value={
                                                                            dispositionReasonsByItem[
                                                                                item.item_id
                                                                            ] ?? ""
                                                                        }
                                                                        onChange={(event) => {
                                                                            setDispositionReasonsByItem(
                                                                                (current) => ({
                                                                                    ...current,
                                                                                    [item.item_id]:
                                                                                        event.target
                                                                                            .value,
                                                                                }),
                                                                            );
                                                                            setRetryKey(null);
                                                                        }}
                                                                    >
                                                                        <option value="">
                                                                            Chọn lý do *
                                                                        </option>
                                                                        {Object.entries(
                                                                            dispositionReasons,
                                                                        ).map(([code, label]) => (
                                                                            <option
                                                                                key={code}
                                                                                value={code}
                                                                            >
                                                                                {label}
                                                                            </option>
                                                                        ))}
                                                                    </select>
                                                                    {dispositionReasonsByItem[
                                                                        item.item_id
                                                                    ] === "other" && (
                                                                        <input
                                                                            className={fieldClass}
                                                                            aria-label={`Ghi chú lý do khác ${item.sku}`}
                                                                            placeholder="Ghi chú *"
                                                                            value={
                                                                                dispositionNotes[
                                                                                    item.item_id
                                                                                ] ?? ""
                                                                            }
                                                                            onChange={(event) => {
                                                                                setDispositionNotes(
                                                                                    (current) => ({
                                                                                        ...current,
                                                                                        [item.item_id]:
                                                                                            event
                                                                                                .target
                                                                                                .value,
                                                                                    }),
                                                                                );
                                                                                setRetryKey(null);
                                                                            }}
                                                                        />
                                                                    )}
                                                                    {!dispositionReasonsByItem[
                                                                        item.item_id
                                                                    ] && (
                                                                        <span className="text-xs text-red-700">
                                                                            Cần chọn lý do cho phần
                                                                            không nhập kho.
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </td>
                                                    )}
                                                </tr>
                                            ))}
                                    </tbody>
                                </table>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {returnMode === "pending_inspection"
                                    ? "Hàng chờ kiểm tra chưa tăng tồn kho. Bạn có thể xử lý phiếu này sau."
                                    : "Số không nhập kho = số trả trừ số nhập lại kho. Phiếu trả hàng không tự hoàn tiền."}
                            </p>
                            <div className="flex gap-2">
                                <button
                                    className={buttonClass}
                                    disabled={!returnValid || busy}
                                    onClick={() => setConfirming(true)}
                                >
                                    Xác nhận trả hàng
                                </button>
                                <button
                                    className={secondaryButtonClass}
                                    onClick={() => setMode(null)}
                                >
                                    Đóng
                                </button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
            <Sheet open={historyOpen} onOpenChange={setHistoryOpen}>
                <SheetContent className="w-full overflow-y-auto sm:max-w-2xl">
                    <SheetHeader>
                        <SheetTitle>Trả hàng & hoàn tiền</SheetTitle>
                        <SheetDescription>Đơn {order.order_code}</SheetDescription>
                    </SheetHeader>
                    {(refunds.data?.data.refunds.length ?? 0) > 0 && (
                        <div className="space-y-2 border-t pt-4 text-sm">
                            <h3 className="font-semibold">Lịch sử hoàn tiền</h3>
                            {refunds.data!.data.refunds.map((entry) => (
                                <p key={entry.id}>
                                    {entry.refund_code} · {money(entry.amount)} ·{" "}
                                    {entry.refund_method} ·{" "}
                                    {new Date(entry.completed_at).toLocaleString("vi-VN")}
                                </p>
                            ))}
                        </div>
                    )}
                    {(returns.data?.data.length ?? 0) > 0 && (
                        <div className="space-y-2 border-t pt-4 text-sm">
                            <h3 className="font-semibold">Phiếu trả hàng</h3>
                            {returns.data!.data.map((entry) => (
                                <div key={entry.id} className="space-y-2 rounded-lg border p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <strong>{entry.return_code}</strong>
                                        {entry.status === "requested" && (
                                            <div className="flex gap-2">
                                                <button
                                                    className={buttonClass}
                                                    onClick={() => {
                                                        setHistoryOpen(false);
                                                        setDecision({ entry, action: "approve" });
                                                    }}
                                                >
                                                    Duyệt
                                                </button>
                                                <button
                                                    className={secondaryButtonClass}
                                                    onClick={() => {
                                                        setHistoryOpen(false);
                                                        setDecision({ entry, action: "reject" });
                                                    }}
                                                >
                                                    Từ chối
                                                </button>
                                            </div>
                                        )}
                                        {entry.status === "approved" && (
                                            <button
                                                className={buttonClass}
                                                onClick={() => {
                                                    setHistoryOpen(false);
                                                    setDecision({ entry, action: "receive" });
                                                }}
                                            >
                                                Xác nhận nhận hàng
                                            </button>
                                        )}
                                        {entry.status === "pending" && (
                                            <button
                                                className={buttonClass}
                                                onClick={() => {
                                                    setHistoryOpen(false);
                                                    openInspection(entry);
                                                }}
                                            >
                                                Xử lý hàng trả
                                            </button>
                                        )}
                                        {entry.status === "completed" &&
                                            Number(order.refundable_amount) > 0 &&
                                            entry.return_value > entry.refunded_amount && (
                                                <button
                                                    className={buttonClass}
                                                    onClick={() => {
                                                        setHistoryOpen(false);
                                                        setReturnId(String(entry.id));
                                                        setReason("return");
                                                        setAmount(
                                                            String(
                                                                Math.min(
                                                                    Number(order.refundable_amount),
                                                                    entry.return_value -
                                                                        entry.refunded_amount,
                                                                ),
                                                            ),
                                                        );
                                                        setMethod(
                                                            order.payment_method === "dealer_wallet"
                                                                ? "dealer_wallet"
                                                                : "bank_transfer",
                                                        );
                                                        setError("");
                                                        setMode("refund");
                                                    }}
                                                >
                                                    Hoàn tiền
                                                </button>
                                            )}
                                    </div>
                                    <p className="text-muted-foreground">
                                        Nhận hàng:{" "}
                                        {new Date(entry.created_at).toLocaleString("vi-VN")} ·{" "}
                                        {entry.received_by?.name ?? "Chưa ghi nhận"}
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        <span className="rounded-full bg-amber-50 px-2 py-1 text-amber-800">
                                            {entry.status === "requested"
                                                ? "Chờ duyệt"
                                                : entry.status === "approved"
                                                  ? "Chờ nhận hàng"
                                                  : entry.status === "rejected"
                                                    ? "Đã từ chối"
                                                    : entry.status === "pending"
                                                      ? "Chờ kiểm tra"
                                                      : entry.items.every(
                                                              (item) =>
                                                                  Number(item.restock_quantity) ===
                                                                  Number(item.quantity),
                                                          )
                                                        ? "Nhập lại toàn bộ"
                                                        : entry.items.every(
                                                                (item) =>
                                                                    Number(
                                                                        item.restock_quantity,
                                                                    ) === 0,
                                                            )
                                                          ? "Không nhập lại"
                                                          : "Nhập lại một phần"}
                                        </span>
                                        <span className="rounded-full bg-sky-50 px-2 py-1 text-sky-800">
                                            {entry.status === "requested" ||
                                            entry.status === "approved" ||
                                            entry.status === "rejected" ||
                                            entry.status === "pending"
                                                ? "Chưa hoàn tiền"
                                                : Number(order.paid_amount) === 0
                                                  ? "Chưa thu tiền"
                                                  : entry.refunded_amount >= entry.return_value &&
                                                      entry.return_value > 0
                                                    ? "Đã hoàn tiền"
                                                    : entry.refunded_amount > 0
                                                      ? "Đã hoàn một phần"
                                                      : "Chờ hoàn tiền"}
                                        </span>
                                    </div>
                                    {entry.requested_by && (
                                        <p className="text-xs">
                                            Người yêu cầu: {entry.requested_by.name} ·{" "}
                                            {entry.request_source}
                                        </p>
                                    )}
                                    <p className="text-xs">
                                        Lý do: {entry.reason_code ?? entry.reason}
                                        {entry.note ? ` · ${entry.note}` : ""}
                                    </p>
                                    {entry.approved_at && (
                                        <p className="text-xs">
                                            Duyệt:{" "}
                                            {new Date(entry.approved_at).toLocaleString("vi-VN")} ·{" "}
                                            {entry.approved_by?.name}
                                        </p>
                                    )}
                                    {entry.received_at && (
                                        <p className="text-xs">
                                            Nhận:{" "}
                                            {new Date(entry.received_at).toLocaleString("vi-VN")} ·{" "}
                                            {entry.received_by?.name}
                                        </p>
                                    )}
                                    {entry.rejected_at && (
                                        <p className="text-xs text-red-700">
                                            Từ chối: {entry.rejection_reason} ·{" "}
                                            {entry.rejected_by?.name}
                                        </p>
                                    )}
                                    {entry.items.map((item) => (
                                        <div key={item.id} className="border-t pt-2">
                                            <strong>{item.product_name ?? item.sku}</strong>{" "}
                                            {item.product_name && (
                                                <span className="text-muted-foreground">
                                                    ({item.sku})
                                                </span>
                                            )}{" "}
                                            · Trả {formatProductQuantity(item.quantity)}
                                            {entry.status === "completed" && (
                                                <>
                                                    {" "}
                                                    · Nhập kho{" "}
                                                    {formatProductQuantity(item.restock_quantity)} ·
                                                    Không nhập kho{" "}
                                                    {formatProductQuantity(
                                                        item.non_restock_quantity,
                                                    )}
                                                    {Number(item.non_restock_quantity) > 0 && (
                                                        <p>
                                                            Lý do:{" "}
                                                            {item.non_restock_reason_code
                                                                ? (dispositionReasons[
                                                                      item.non_restock_reason_code
                                                                  ] ?? item.non_restock_reason_code)
                                                                : "Chưa ghi nhận"}
                                                            {item.non_restock_note
                                                                ? ` · ${item.non_restock_note}`
                                                                : ""}
                                                        </p>
                                                    )}
                                                    {item.stock_movement_id && (
                                                        <p className="text-xs text-muted-foreground">
                                                            Biến động kho #{item.stock_movement_id}
                                                        </p>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    ))}
                                    {entry.completed_at && (
                                        <p className="text-xs text-muted-foreground">
                                            Xử lý:{" "}
                                            {new Date(entry.completed_at).toLocaleString("vi-VN")} ·{" "}
                                            {entry.processed_by?.name ?? "Chưa ghi nhận"}
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </SheetContent>
            </Sheet>
            <Dialog
                open={decision !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) setDecision(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {decision?.action === "approve"
                                ? "Duyệt yêu cầu trả hàng?"
                                : decision?.action === "reject"
                                  ? "Từ chối yêu cầu trả hàng?"
                                  : "Xác nhận đã nhận hàng trả?"}
                        </DialogTitle>
                        <DialogDescription>
                            Phiếu {decision?.entry.return_code}. Thao tác này sẽ được lưu vào lịch
                            sử.
                        </DialogDescription>
                    </DialogHeader>
                    {decision?.action === "reject" && (
                        <label className="grid gap-1 text-sm">
                            Lý do từ chối *
                            <textarea
                                className={fieldClass}
                                value={rejectionReason}
                                onChange={(event) => setRejectionReason(event.target.value)}
                            />
                        </label>
                    )}
                    {error && (
                        <p role="alert" className="text-sm text-red-700">
                            {error}
                        </p>
                    )}
                    <div className="flex justify-end gap-2">
                        <button
                            className={secondaryButtonClass}
                            disabled={busy}
                            onClick={() => setDecision(null)}
                        >
                            Hủy
                        </button>
                        <button
                            className={buttonClass}
                            disabled={
                                busy || (decision?.action === "reject" && !rejectionReason.trim())
                            }
                            onClick={() => void submitDecision()}
                        >
                            {busy ? "Đang xử lý..." : "Xác nhận"}
                        </button>
                    </div>
                </DialogContent>
            </Dialog>
            <AlertDialog
                open={inspectionReturn !== null && !confirming}
                onOpenChange={(open) => !busy && !confirming && !open && setInspectionReturn(null)}
            >
                <AlertDialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Xử lý hàng trả {inspectionReturn?.return_code}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Chọn số lượng nhập lại kho cho từng sản phẩm. Phần còn lại cần ghi rõ lý
                            do.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <div className="space-y-3">
                        {inspectionReturn?.items.map((item) => {
                            const restock = inspectionRestocks[item.id] ?? item.quantity;
                            const needsReason = Number(restock) < Number(item.quantity);
                            return (
                                <div
                                    key={item.id}
                                    className="grid gap-2 rounded-lg border p-3 sm:grid-cols-2"
                                >
                                    <p className="sm:col-span-2">
                                        <strong>{item.product_name ?? item.sku}</strong>{" "}
                                        {item.product_name && (
                                            <span className="text-muted-foreground">
                                                ({item.sku})
                                            </span>
                                        )}{" "}
                                        · Đã nhận {formatProductQuantity(item.quantity)}
                                    </p>
                                    <label className="grid gap-1">
                                        Nhập lại kho
                                        <input
                                            className={fieldClass}
                                            type="number"
                                            min={0}
                                            max={Number(item.quantity)}
                                            step={1}
                                            value={restock}
                                            onChange={(event) => {
                                                setInspectionRestocks((current) => ({
                                                    ...current,
                                                    [item.id]: event.target.value,
                                                }));
                                                setRetryKey(null);
                                            }}
                                        />
                                    </label>
                                    <p className="self-end">
                                        Không nhập kho:{" "}
                                        {Math.max(0, Number(item.quantity) - Number(restock))}
                                    </p>
                                    {needsReason && (
                                        <>
                                            <label className="grid gap-1">
                                                Lý do *
                                                <select
                                                    className={fieldClass}
                                                    value={inspectionReasons[item.id] ?? ""}
                                                    onChange={(event) => {
                                                        setInspectionReasons((current) => ({
                                                            ...current,
                                                            [item.id]: event.target.value,
                                                        }));
                                                        setRetryKey(null);
                                                    }}
                                                >
                                                    <option value="">Chọn lý do</option>
                                                    {Object.entries(dispositionReasons).map(
                                                        ([code, label]) => (
                                                            <option key={code} value={code}>
                                                                {label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </label>
                                            <label className="grid gap-1">
                                                Ghi chú{" "}
                                                {inspectionReasons[item.id] === "other" ? "*" : ""}
                                                <input
                                                    className={fieldClass}
                                                    value={inspectionNotes[item.id] ?? ""}
                                                    onChange={(event) => {
                                                        setInspectionNotes((current) => ({
                                                            ...current,
                                                            [item.id]: event.target.value,
                                                        }));
                                                        setRetryKey(null);
                                                    }}
                                                />
                                            </label>
                                        </>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    {error && (
                        <p role="alert" className="text-sm text-red-700">
                            {error}
                        </p>
                    )}
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>Hủy</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={!inspectionValid || busy}
                            onClick={(event) => {
                                event.preventDefault();
                                setConfirming(true);
                            }}
                        >
                            Xác nhận xử lý
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <AlertDialog open={confirming} onOpenChange={(open) => !busy && setConfirming(open)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {inspectionReturn
                                ? "Xác nhận xử lý hàng trả?"
                                : mode === "refund"
                                  ? "Xác nhận đã hoàn tiền?"
                                  : "Xác nhận đã nhận hàng trả?"}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {inspectionReturn
                                ? `Nhập lại ${inspectionReturn.items.reduce((sum, item) => sum + Number(inspectionRestocks[item.id] ?? item.quantity), 0)} sản phẩm vào kho ${order.warehouse.code}.`
                                : mode === "refund"
                                  ? `Đã hoàn ${money(amount)} cho đơn ${order.order_code}. Bút toán không thể sửa sau khi lưu.`
                                  : `Ghi nhận ${selectedLines.length} dòng hàng trả cho đơn ${order.order_code}; chỉ số nhập lại kho tăng tồn.`}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>Quay lại</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={(event) => {
                                event.preventDefault();
                                void submit();
                            }}
                            disabled={busy}
                        >
                            {busy ? "Đang xử lý..." : "Xác nhận"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </section>
    );
}
