import { useEffect, useState } from "react";
import { Link } from "@tanstack/react-router";
import { toast } from "sonner";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { DealerWalletTransaction } from "./types";
import { walletDate, walletMethod, walletMoney, walletType } from "./dealerWalletFormat";

export function DealerWalletTransactionsTable({
    transactions,
    showDealer = false,
    openCode,
    onClose,
}: {
    transactions: DealerWalletTransaction[];
    showDealer?: boolean;
    openCode?: string | null;
    onClose?: () => void;
}) {
    const [selected, setSelected] = useState<DealerWalletTransaction | null>(null);
    useEffect(() => {
        if (openCode) {
            setSelected(transactions.find((item) => item.transaction_code === openCode) ?? null);
        }
    }, [openCode, transactions]);
    const copyCode = async (code: string) => {
        try {
            await navigator.clipboard.writeText(code);
            toast.success("Đã sao chép mã giao dịch ví.");
        } catch {
            toast.error("Không thể sao chép mã giao dịch.");
        }
    };
    if (transactions.length === 0)
        return <p className="p-5 text-sm text-muted-foreground">Chưa có giao dịch ví.</p>;
    return (
        <>
            <div className="overflow-x-auto rounded-xl border bg-card">
                <table className="w-full min-w-[800px] text-left text-sm">
                    <thead className="bg-muted/50">
                        <tr>
                            <th className="p-3">Thời gian</th>
                            {showDealer && <th className="p-3">Đại lý</th>}
                            <th className="p-3">Loại giao dịch</th>
                            <th className="p-3">Mã tham chiếu</th>
                            <th className="p-3 text-right">Biến động</th>
                            <th className="p-3 text-right">Số dư sau giao dịch</th>
                        </tr>
                    </thead>
                    <tbody>
                        {transactions.map((item) => (
                            <tr
                                key={item.id}
                                tabIndex={0}
                                role="button"
                                aria-label={`Xem giao dịch ${item.transaction_code}`}
                                className="cursor-pointer border-t hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-primary"
                                onClick={() => setSelected(item)}
                                onKeyDown={(event) => {
                                    if (event.key === "Enter" || event.key === " ") {
                                        event.preventDefault();
                                        setSelected(item);
                                    }
                                }}
                            >
                                <td className="p-3 whitespace-nowrap">
                                    {walletDate(item.created_at)}
                                </td>
                                {showDealer && (
                                    <td className="p-3">
                                        {item.wallet?.dealer_account ? (
                                            <Link
                                                to="/admin/dealers/$id"
                                                params={{
                                                    id: String(item.wallet.dealer_account.id),
                                                }}
                                                search={{ tab: "wallet" }}
                                                className="text-primary underline"
                                                onClick={(event) => event.stopPropagation()}
                                                onKeyDown={(event) => event.stopPropagation()}
                                            >
                                                {item.wallet.dealer_account.legal_name}
                                            </Link>
                                        ) : (
                                            "—"
                                        )}
                                    </td>
                                )}
                                <td className="p-3">{walletType(item.type)}</td>
                                <td className="p-3">
                                    {item.deposit?.deposit_code ??
                                        item.refund?.refund_code ??
                                        item.sales_order?.order_code ??
                                        item.transaction_code}
                                </td>
                                <td
                                    className={`p-3 text-right font-semibold ${item.direction === "credit" ? "text-emerald-700" : "text-rose-700"}`}
                                >
                                    {item.direction === "credit" ? "+" : "−"}
                                    {walletMoney(item.amount)}
                                </td>
                                <td className="p-3 text-right font-medium">
                                    {walletMoney(item.balance_after)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Dialog
                open={selected !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelected(null);
                        onClose?.();
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-xl">
                    <DialogHeader>
                        <DialogTitle>Chi tiết giao dịch ví</DialogTitle>
                    </DialogHeader>
                    {selected && (
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <dt className="text-muted-foreground">Mã giao dịch ví</dt>
                                <dd className="flex flex-wrap items-center gap-2 break-all font-medium">
                                    {selected.transaction_code}
                                    <button
                                        className="text-primary underline"
                                        onClick={() => void copyCode(selected.transaction_code)}
                                    >
                                        Sao chép
                                    </button>
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Đại lý</dt>
                                <dd>{selected.wallet?.dealer_account?.legal_name ?? "—"}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Mã tham chiếu</dt>
                                <dd className="break-all">
                                    {selected.deposit?.deposit_code ??
                                        selected.refund?.refund_code ??
                                        selected.sales_order?.order_code ??
                                        "—"}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Loại</dt>
                                <dd>{walletType(selected.type)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Số tiền</dt>
                                <dd>
                                    {selected.direction === "credit" ? "+" : "−"}
                                    {walletMoney(selected.amount)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Phương thức</dt>
                                <dd>{walletMethod(selected.deposit?.method)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Mã tham chiếu</dt>
                                <dd className="break-all">
                                    {selected.deposit?.external_reference ?? "—"}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Số dư trước</dt>
                                <dd>{walletMoney(selected.balance_before)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Số dư sau</dt>
                                <dd>{walletMoney(selected.balance_after)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Thực hiện bởi</dt>
                                <dd>{selected.actor?.name ?? "Hệ thống"}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Thời gian</dt>
                                <dd>{walletDate(selected.created_at)}</dd>
                            </div>
                            {selected.sales_order_id || selected.refund?.sales_order_id ? (
                                <div className="sm:col-span-2">
                                    <dt className="text-muted-foreground">Đơn hàng liên quan</dt>
                                    <dd>
                                        <Link
                                            to="/admin/sales-orders/$id"
                                            params={{
                                                id: String(
                                                    selected.sales_order_id ??
                                                        selected.refund?.sales_order_id,
                                                ),
                                            }}
                                            className="text-primary underline"
                                        >
                                            {selected.sales_order?.order_code ??
                                                `Đơn #${selected.sales_order_id ?? selected.refund?.sales_order_id}`}
                                        </Link>
                                    </dd>
                                </div>
                            ) : null}
                            {selected.deposit?.note && (
                                <div className="sm:col-span-2">
                                    <dt className="text-muted-foreground">Ghi chú</dt>
                                    <dd>{selected.deposit.note}</dd>
                                </div>
                            )}
                        </dl>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
