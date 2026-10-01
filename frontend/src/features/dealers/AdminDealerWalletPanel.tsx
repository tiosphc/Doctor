import { useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { ErrorState, LoadingState } from "@/components/common/AsyncState";
import { buttonClass, fieldClass, secondaryButtonClass } from "@/pages/admin/ProductAdminShared";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { dealerApi } from "./api";
import { DealerWalletTransactionsTable } from "./DealerWalletTransactionsTable";
import { walletDate, walletMethod, walletMoney } from "./dealerWalletFormat";

type WalletTab = "deposits" | "transactions";

export function AdminDealerWalletPanel({ dealerId }: { dealerId: number }) {
    const client = useQueryClient();
    const [tab, setTab] = useState<WalletTab>("transactions");
    const [depositOpen, setDepositOpen] = useState(false);
    const [amount, setAmount] = useState("");
    const [amountFocused, setAmountFocused] = useState(false);
    const [method, setMethod] = useState<"bank_transfer" | "cash" | "other_manual">(
        "bank_transfer",
    );
    const [reference, setReference] = useState("");
    const [note, setNote] = useState("");
    const [search, setSearch] = useState("");
    const [openTransactionCode, setOpenTransactionCode] = useState<string | null>(null);
    const [transactionType, setTransactionType] = useState("");
    const [from, setFrom] = useState("");
    const [to, setTo] = useState("");
    const [page, setPage] = useState(1);
    const [depositPage, setDepositPage] = useState(1);
    const operationKey = useRef(crypto.randomUUID());
    const submitting = useRef(false);
    const wallet = useQuery({
        queryKey: ["admin-dealer-wallet", dealerId],
        queryFn: () => dealerApi.adminWallet(dealerId),
    });
    const filters = { search, type: transactionType, from, to, page };
    const transactions = useQuery({
        queryKey: ["admin-dealer-wallet-transactions", dealerId, filters],
        queryFn: () => dealerApi.adminWalletTransactions(dealerId, filters),
        enabled: tab === "transactions",
    });
    const deposits = useQuery({
        queryKey: ["admin-dealer-wallet-deposits", dealerId, depositPage],
        queryFn: () => dealerApi.adminWalletDeposits(dealerId, { page: depositPage }),
        enabled: tab === "deposits",
    });
    const record = useMutation({
        mutationFn: () =>
            dealerApi.adminWalletDeposit(dealerId, {
                amount,
                method,
                operation_key: operationKey.current,
                ...(reference.trim() ? { external_reference: reference.trim() } : {}),
                ...(note.trim() ? { note: note.trim() } : {}),
            }),
        onSuccess: async () => {
            operationKey.current = crypto.randomUUID();
            setAmount("");
            setReference("");
            setNote("");
            setDepositOpen(false);
            toast.success("Đã ghi nhận tiền nạp thành công.");
            await Promise.all([
                client.invalidateQueries({ queryKey: ["admin-dealer-wallet", dealerId] }),
                client.invalidateQueries({
                    queryKey: ["admin-dealer-wallet-transactions", dealerId],
                }),
                client.invalidateQueries({ queryKey: ["admin-dealer-wallet-deposits", dealerId] }),
                client.invalidateQueries({ queryKey: ["admin-dealer-wallets"] }),
                client.invalidateQueries({ queryKey: ["admin-all-wallet-transactions"] }),
            ]);
        },
        onError: (error) => toast.error(`Không thể ghi nhận tiền nạp. ${errorMessage(error)}`),
    });
    const errors = firstFieldErrors(record.error);
    return (
        <section className="space-y-5 rounded-xl border bg-card p-5 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl text-primary">Ví đại lý</h2>
                    <p className="text-sm text-muted-foreground">
                        Dữ liệu chỉ thuộc đại lý đang xem.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button className={buttonClass} onClick={() => setDepositOpen(true)}>
                        + Ghi nhận tiền nạp
                    </button>
                    <button className={secondaryButtonClass} onClick={() => setTab("deposits")}>
                        Xem lịch sử nạp
                    </button>
                </div>
            </div>
            {wallet.isPending ? (
                <LoadingState />
            ) : wallet.isError ? (
                <ErrorState
                    message={errorMessage(wallet.error)}
                    retry={() => void wallet.refetch()}
                />
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        ["Số dư hiện tại", walletMoney(wallet.data.data.balance)],
                        ["Tổng đã nạp", walletMoney(wallet.data.data.total_deposited ?? "0")],
                        ["Tổng đã chi", walletMoney(wallet.data.data.total_spent ?? "0")],
                        ["Tổng hoàn tiền", walletMoney(wallet.data.data.total_refunded ?? "0")],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-lg bg-muted/50 p-4">
                            <p className="text-xs text-muted-foreground">{label}</p>
                            <p className="mt-1 font-semibold text-primary">{value}</p>
                        </div>
                    ))}
                </div>
            )}
            <div className="flex gap-2 overflow-x-auto border-b pb-2 text-sm">
                {(
                    [
                        ["transactions", "Lịch sử giao dịch"],
                        ["deposits", "Lịch sử nạp tiền"],
                    ] as const
                ).map(([key, label]) => (
                    <button
                        key={key}
                        className={`whitespace-nowrap rounded-md px-3 py-2 ${tab === key ? "bg-primary text-primary-foreground" : "hover:bg-muted"}`}
                        onClick={() => setTab(key)}
                    >
                        {label}
                    </button>
                ))}
            </div>
            {tab === "deposits" && (
                <div className="space-y-3">
                    {deposits.isPending ? (
                        <LoadingState />
                    ) : deposits.isError ? (
                        <ErrorState
                            message={errorMessage(deposits.error)}
                            retry={() => void deposits.refetch()}
                        />
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[680px] text-left text-sm">
                                <thead className="bg-muted/50">
                                    <tr>
                                        <th className="p-3">Mã giao dịch ví</th>
                                        <th className="p-3">Thời gian</th>
                                        <th className="p-3">Phương thức</th>
                                        <th className="p-3">Mã tham chiếu</th>
                                        <th className="p-3">Số tiền</th>
                                        <th className="p-3">Người thực hiện</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {deposits.data.data.map((item) => (
                                        <tr key={item.id} className="border-t">
                                            <td className="p-3">
                                                <button
                                                    className="text-primary underline"
                                                    title={
                                                        item.transaction?.transaction_code ??
                                                        item.deposit_code
                                                    }
                                                    onClick={() => {
                                                        setOpenTransactionCode(
                                                            item.transaction?.transaction_code ??
                                                                null,
                                                        );
                                                        setPage(1);
                                                        setSearch(
                                                            item.transaction?.transaction_code ??
                                                                "",
                                                        );
                                                        setTab("transactions");
                                                    }}
                                                >
                                                    {(
                                                        item.transaction?.transaction_code ??
                                                        item.deposit_code
                                                    ).slice(0, 11)}
                                                    ...
                                                </button>
                                            </td>
                                            <td className="p-3">{walletDate(item.completed_at)}</td>
                                            <td className="p-3">{walletMethod(item.method)}</td>
                                            <td className="p-3">
                                                {item.external_reference ?? "—"}
                                            </td>
                                            <td className="p-3 font-medium text-emerald-700">
                                                +{walletMoney(item.amount)}
                                            </td>
                                            <td className="p-3">{item.recorder?.name ?? "—"}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {deposits.data.data.length === 0 && (
                                <p className="p-4 text-sm text-muted-foreground">
                                    Chưa có khoản nạp.
                                </p>
                            )}
                        </div>
                    )}
                    {deposits.data && deposits.data.last_page > 1 && (
                        <Pager
                            page={depositPage}
                            last={deposits.data.last_page}
                            setPage={setDepositPage}
                        />
                    )}
                </div>
            )}
            {tab === "transactions" && (
                <div className="space-y-3">
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <input
                            className={fieldClass}
                            aria-label="Tìm biến động ví"
                            placeholder="Mã WTX / tham chiếu"
                            value={search}
                            onChange={(event) => {
                                setSearch(event.target.value);
                                setPage(1);
                            }}
                        />
                        <select
                            className={fieldClass}
                            aria-label="Loại biến động"
                            value={transactionType}
                            onChange={(event) => {
                                setTransactionType(event.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">Tất cả loại</option>
                            <option value="deposit_credit">Nạp tiền</option>
                            <option value="order_debit">Thanh toán đơn hàng</option>
                            <option value="refund_credit">Hoàn tiền</option>
                            <option value="adjustment">Điều chỉnh số dư</option>
                        </select>
                        <input
                            className={fieldClass}
                            type="date"
                            aria-label="Từ ngày"
                            value={from}
                            onChange={(event) => {
                                setFrom(event.target.value);
                                setPage(1);
                            }}
                        />
                        <input
                            className={fieldClass}
                            type="date"
                            aria-label="Đến ngày"
                            value={to}
                            onChange={(event) => {
                                setTo(event.target.value);
                                setPage(1);
                            }}
                        />
                    </div>
                    {transactions.isPending ? (
                        <LoadingState />
                    ) : transactions.isError ? (
                        <ErrorState
                            message={errorMessage(transactions.error)}
                            retry={() => void transactions.refetch()}
                        />
                    ) : (
                        <DealerWalletTransactionsTable
                            transactions={transactions.data.data}
                            openCode={openTransactionCode}
                            onClose={() => setOpenTransactionCode(null)}
                        />
                    )}
                    {transactions.data && transactions.data.last_page > 1 && (
                        <Pager page={page} last={transactions.data.last_page} setPage={setPage} />
                    )}
                </div>
            )}
            <Dialog
                open={depositOpen}
                onOpenChange={(open) => {
                    if (!record.isPending) setDepositOpen(open);
                }}
            >
                <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Ghi nhận tiền nạp</DialogTitle>
                    </DialogHeader>
                    <div className="grid gap-3 text-sm">
                        <label className="grid gap-1">
                            Số tiền *
                            <input
                                className={fieldClass}
                                type="text"
                                inputMode="numeric"
                                value={
                                    amountFocused
                                        ? amount
                                        : amount
                                          ? walletMoney(amount).replace(" ₫", "")
                                          : ""
                                }
                                onFocus={() => setAmountFocused(true)}
                                onBlur={() => setAmountFocused(false)}
                                onChange={(event) => {
                                    const digits = event.target.value.replaceAll(".", "");
                                    if (/^\d{0,16}$/.test(digits)) {
                                        setAmount(digits);
                                        operationKey.current = crypto.randomUUID();
                                    }
                                }}
                                placeholder="1.000.000"
                            />
                            {errors["amount"] && (
                                <span className="text-xs text-red-700">{errors["amount"]}</span>
                            )}
                        </label>
                        <label className="grid gap-1">
                            Phương thức *
                            <select
                                className={fieldClass}
                                value={method}
                                onChange={(event) => {
                                    setMethod(event.target.value as typeof method);
                                    operationKey.current = crypto.randomUUID();
                                }}
                            >
                                <option value="bank_transfer">Chuyển khoản</option>
                                <option value="cash">Tiền mặt</option>
                                <option value="other_manual">Khác</option>
                            </select>
                        </label>
                        <label className="grid gap-1">
                            Mã tham chiếu
                            <input
                                className={fieldClass}
                                value={reference}
                                onChange={(event) => {
                                    setReference(event.target.value);
                                    operationKey.current = crypto.randomUUID();
                                }}
                            />
                        </label>
                        <label className="grid gap-1">
                            Ghi chú
                            <input
                                className={fieldClass}
                                value={note}
                                onChange={(event) => {
                                    setNote(event.target.value);
                                    operationKey.current = crypto.randomUUID();
                                }}
                            />
                        </label>
                        <div className="flex flex-wrap justify-end gap-2">
                            <button
                                className={secondaryButtonClass}
                                disabled={record.isPending}
                                onClick={() => setDepositOpen(false)}
                            >
                                Hủy
                            </button>
                            <button
                                className={buttonClass}
                                disabled={record.isPending || !/^[1-9]\d*$/.test(amount)}
                                onClick={() => {
                                    if (submitting.current) return;
                                    submitting.current = true;
                                    record.mutate(undefined, {
                                        onSettled: () => {
                                            submitting.current = false;
                                        },
                                    });
                                }}
                            >
                                {record.isPending ? "Đang xử lý..." : "Ghi nhận tiền nạp"}
                            </button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function Pager({
    page,
    last,
    setPage,
}: {
    page: number;
    last: number;
    setPage: (page: number) => void;
}) {
    return (
        <div className="flex justify-end gap-3 text-sm">
            <button disabled={page <= 1} onClick={() => setPage(page - 1)}>
                Trước
            </button>
            <span>
                {page}/{last}
            </span>
            <button disabled={page >= last} onClick={() => setPage(page + 1)}>
                Sau
            </button>
        </div>
    );
}
