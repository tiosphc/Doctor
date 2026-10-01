import { useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { errorMessage } from "@/services/api";
import {
    customerReturnApi,
    type CustomerReturn,
    type ReturnChannel,
} from "@/services/customerReturnApi";

const reasons: Record<string, string> = {
    defective: "Sản phẩm bị lỗi",
    damaged_in_delivery: "Hư hỏng khi vận chuyển",
    wrong_product: "Giao sai sản phẩm",
    wrong_specification: "Sai quy cách",
    not_as_described: "Không đúng mô tả",
    other: "Lý do khác",
};

const statusLabels: Record<string, string> = {
    requested: "Chờ duyệt",
    approved: "Đã duyệt, chờ nhận hàng",
    rejected: "Đã từ chối",
    pending: "Đã nhận, chờ kiểm tra",
    completed: "Đã kiểm tra",
};

const eligibilityMessages: Record<string, string> = {
    ORDER_CANCELLED: "Đơn hàng đã hủy.",
    ORDER_NOT_DELIVERED: "Bạn có thể yêu cầu trả hàng sau khi đơn đã giao đủ.",
    RETURN_WINDOW_EXPIRED: "Đã hết thời hạn trả hàng.",
    NOTHING_RETURNABLE: "Không còn sản phẩm nào có thể yêu cầu trả.",
};

export function CustomerReturnSection({
    channel,
    orderId,
}: {
    channel: ReturnChannel;
    orderId: number;
}) {
    const client = useQueryClient();
    const channelKey = channel.kind === "dealer" ? `dealer-${channel.accountId}` : "retail";
    const queryKey = ["customer-returns", channelKey, orderId];
    const eligibilityKey = ["return-eligibility", channelKey, orderId];
    const eligibility = useQuery({
        queryKey: eligibilityKey,
        queryFn: () => customerReturnApi.eligibility(channel, orderId),
    });
    const returns = useQuery({
        queryKey,
        queryFn: () => customerReturnApi.list(channel, orderId),
    });
    const [open, setOpen] = useState(false);
    const [viewing, setViewing] = useState<CustomerReturn | null>(null);
    const [busy, setBusy] = useState(false);
    const [reason, setReason] = useState("");
    const [note, setNote] = useState("");
    const [quantities, setQuantities] = useState<Record<number, string>>({});
    const [error, setError] = useState("");
    const operationKey = useRef<string | null>(null);
    const lines = (eligibility.data?.data.items ?? []).filter(
        (item) => Number(item.returnable_quantity) > 0,
    );
    const activeReturn = returns.data?.data.find((entry) =>
        ["requested", "approved", "pending"].includes(entry.status),
    );
    const selected = lines.filter((item) => Number(quantities[item.item_id]) > 0);
    const valid =
        Boolean(reason) &&
        (reason !== "other" || Boolean(note.trim())) &&
        selected.length > 0 &&
        selected.every((item) => {
            const quantity = quantities[item.item_id] ?? "";
            return /^\d+$/.test(quantity) && Number(quantity) <= Number(item.returnable_quantity);
        });

    async function submit(): Promise<void> {
        if (!valid || busy) return;
        setBusy(true);
        setError("");
        operationKey.current ??= crypto.randomUUID();
        try {
            await customerReturnApi.request(channel, orderId, {
                operation_key: operationKey.current,
                reason_code: reason,
                ...(note.trim() && { note: note.trim() }),
                items: selected.map((item) => ({
                    item_id: item.item_id,
                    quantity: quantities[item.item_id]!,
                })),
            });
            operationKey.current = null;
            setOpen(false);
            setQuantities({});
            setReason("");
            setNote("");
            toast.success("Đã gửi yêu cầu trả hàng thành công.");
            await Promise.all([
                client.invalidateQueries({ queryKey }),
                client.invalidateQueries({ queryKey: eligibilityKey }),
            ]);
        } catch (cause) {
            setError(errorMessage(cause));
            toast.error(errorMessage(cause));
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="rounded-xl border bg-card p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold text-primary">Trả hàng & hoàn tiền</h2>
                    {eligibility.isError ? (
                        <p role="alert" className="mt-2 text-sm text-red-700">
                            {errorMessage(eligibility.error)}
                        </p>
                    ) : eligibility.data?.data.return_eligible ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                            Yêu cầu trong {eligibility.data.data.return_window_days} ngày từ khi
                            giao hàng.
                            {eligibility.data.data.return_deadline &&
                                ` Hạn: ${new Date(eligibility.data.data.return_deadline).toLocaleString("vi-VN")}.`}
                        </p>
                    ) : (
                        <p className="mt-2 text-sm text-muted-foreground">
                            {eligibility.data?.data.return_ineligible_reason ===
                            "RETURN_WINDOW_EXPIRED"
                                ? `Đã hết thời hạn trả hàng (${eligibility.data.data.return_window_days} ngày kể từ ngày nhận hàng).`
                                : (eligibilityMessages[
                                      eligibility.data?.data.return_ineligible_reason ?? ""
                                  ] ?? "Đang kiểm tra điều kiện trả hàng.")}
                            {eligibility.data?.data.return_deadline &&
                                ` Hạn: ${new Date(eligibility.data.data.return_deadline).toLocaleString("vi-VN")}.`}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    {activeReturn && (
                        <button
                            type="button"
                            onClick={() => setViewing(activeReturn)}
                            className="rounded-md border px-4 py-2 text-sm font-medium"
                        >
                            Xem yêu cầu trả hàng
                        </button>
                    )}
                    {eligibility.data?.data.return_eligible && (
                        <button
                            type="button"
                            onClick={() => {
                                setError("");
                                setOpen(true);
                            }}
                            className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                        >
                            {activeReturn ? "Yêu cầu trả thêm" : "Yêu cầu trả hàng"}
                        </button>
                    )}
                </div>
            </div>
            {returns.isError && (
                <p role="alert" className="mt-3 text-sm text-red-700">
                    {errorMessage(returns.error)}
                </p>
            )}
            {(returns.data?.data.length ?? 0) > 0 && (
                <div className="mt-5 space-y-3 border-t pt-4">
                    <h3 className="font-medium">Lịch sử trả hàng</h3>
                    {returns.data!.data.map((entry) => (
                        <button
                            type="button"
                            key={entry.id}
                            onClick={() => setViewing(entry)}
                            className="block w-full rounded-lg border p-3 text-left text-sm hover:border-primary"
                        >
                            <div className="flex flex-wrap justify-between gap-2">
                                <strong>{entry.return_code}</strong>
                                <span>{statusLabels[entry.status] ?? entry.status}</span>
                            </div>
                            <p className="mt-1 text-muted-foreground">
                                {new Date(entry.requested_at).toLocaleString("vi-VN")} ·{" "}
                                {reasons[entry.reason_code ?? ""] ?? entry.reason_code}
                            </p>
                            <p className="mt-1">
                                {entry.items
                                    .map((item) => `${item.sku} × ${item.quantity}`)
                                    .join(", ")}
                            </p>
                            {entry.rejection_reason && (
                                <p className="mt-1 text-red-700">
                                    Lý do từ chối: {entry.rejection_reason}
                                </p>
                            )}
                            {entry.status === "completed" && (
                                <p className="mt-1">
                                    Đã hoàn:{" "}
                                    {new Intl.NumberFormat("vi-VN").format(entry.refunded_amount)} ₫
                                </p>
                            )}
                        </button>
                    ))}
                </div>
            )}
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!busy) setOpen(value);
                }}
            >
                <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Yêu cầu trả hàng · Đơn #{orderId}</DialogTitle>
                        <DialogDescription>
                            Chọn sản phẩm và số lượng cần trả. Nhân viên sẽ xem xét yêu cầu trước
                            khi nhận hàng.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        {lines.map((item) => (
                            <label
                                key={item.item_id}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm"
                            >
                                <span>
                                    <strong>{item.product_name}</strong>
                                    <br />
                                    SKU: {item.sku}
                                    <br />
                                    Đã giao: {item.fulfilled_quantity} · Đã trả:{" "}
                                    {item.returned_quantity} · Còn được trả:{" "}
                                    {item.returnable_quantity} {item.unit_code}
                                </span>
                                <input
                                    type="number"
                                    min={0}
                                    max={Number(item.returnable_quantity)}
                                    step={1}
                                    className="w-24 rounded-md border px-3 py-2"
                                    aria-label={`Số lượng trả ${item.sku}`}
                                    value={quantities[item.item_id] ?? ""}
                                    onChange={(event) => {
                                        setQuantities((current) => ({
                                            ...current,
                                            [item.item_id]: event.target.value,
                                        }));
                                        operationKey.current = null;
                                    }}
                                />
                            </label>
                        ))}
                        <label className="grid gap-1 text-sm">
                            Lý do *
                            <select
                                className="rounded-md border px-3 py-2"
                                value={reason}
                                onChange={(event) => {
                                    setReason(event.target.value);
                                    operationKey.current = null;
                                }}
                            >
                                <option value="">Chọn lý do</option>
                                {Object.entries(reasons).map(([code, label]) => (
                                    <option key={code} value={code}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Mô tả {reason === "other" ? "*" : "(không bắt buộc)"}
                            <textarea
                                className="min-h-20 rounded-md border px-3 py-2"
                                value={note}
                                onChange={(event) => {
                                    setNote(event.target.value);
                                    operationKey.current = null;
                                }}
                            />
                        </label>
                        {error && (
                            <p role="alert" className="text-sm text-red-700">
                                {error}
                            </p>
                        )}
                    </div>
                    <DialogFooter>
                        <button
                            type="button"
                            className="rounded-md border px-4 py-2 text-sm"
                            onClick={() => setOpen(false)}
                            disabled={busy}
                        >
                            Hủy
                        </button>
                        <button
                            type="button"
                            className="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50"
                            onClick={() => void submit()}
                            disabled={!valid || busy}
                        >
                            {busy ? "Đang gửi..." : "Gửi yêu cầu"}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Dialog
                open={viewing !== null}
                onOpenChange={(value) => {
                    if (!value) setViewing(null);
                }}
            >
                <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Yêu cầu {viewing?.return_code}</DialogTitle>
                        <DialogDescription>{statusLabels[viewing?.status ?? ""]}</DialogDescription>
                    </DialogHeader>
                    {viewing && (
                        <div className="space-y-3 text-sm">
                            <p>
                                Lý do: {reasons[viewing.reason_code ?? ""] ?? viewing.reason_code}
                            </p>
                            {viewing.note && <p>Mô tả: {viewing.note}</p>}
                            <div className="rounded-lg border p-3">
                                {viewing.items.map((item) => (
                                    <p key={item.id}>
                                        {item.product_name} ({item.sku}) × {item.quantity}
                                    </p>
                                ))}
                            </div>
                            <ol className="space-y-2 border-l pl-4">
                                <li>
                                    Đã gửi yêu cầu ·{" "}
                                    {new Date(viewing.requested_at).toLocaleString("vi-VN")}
                                </li>
                                {viewing.approved_at && (
                                    <li>
                                        Đã chấp nhận ·{" "}
                                        {new Date(viewing.approved_at).toLocaleString("vi-VN")}
                                    </li>
                                )}
                                {viewing.status === "approved" && (
                                    <li>Vui lòng gửi/trả hàng theo hướng dẫn của cửa hàng.</li>
                                )}
                                {viewing.rejected_at && (
                                    <li className="text-red-700">
                                        Đã từ chối ·{" "}
                                        {new Date(viewing.rejected_at).toLocaleString("vi-VN")}
                                        <br />
                                        Lý do: {viewing.rejection_reason}
                                    </li>
                                )}
                                {viewing.received_at && (
                                    <li>
                                        Đã nhận hàng trả ·{" "}
                                        {new Date(viewing.received_at).toLocaleString("vi-VN")}
                                    </li>
                                )}
                                {viewing.completed_at && (
                                    <li>
                                        Đã kiểm tra hàng ·{" "}
                                        {new Date(viewing.completed_at).toLocaleString("vi-VN")}
                                    </li>
                                )}
                                {viewing.refunded_at && (
                                    <li>
                                        Đã hoàn{" "}
                                        {new Intl.NumberFormat("vi-VN").format(
                                            viewing.refunded_amount,
                                        )}{" "}
                                        ₫ · {new Date(viewing.refunded_at).toLocaleString("vi-VN")}
                                    </li>
                                )}
                            </ol>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
