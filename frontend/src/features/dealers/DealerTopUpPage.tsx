import { Link } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { ArrowDownLeft, ArrowUpRight, WalletCards } from "lucide-react";
import { EmptyState, ErrorState, LoadingState } from "@/components/common/AsyncState";
import { useAuth } from "@/contexts/AuthContext";
import { errorMessage } from "@/services/api";
import { dealerApi, dealerKeys } from "./api";
import { DealerWalletDepositRequestPanel } from "./DealerWalletDepositRequestPanel";

const money = (value: string | null | undefined) =>
    value === null || value === undefined
        ? "—"
        : new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(
              Number(value),
          );

export function DealerTopUpPage() {
    const { user, isLoading } = useAuth();
    const [selectedAccountId, setSelectedAccountId] = useState<number | null>(null);
    const accounts = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled: user?.role === "customer",
    });
    const account =
        accounts.data?.data.find((item) => item.id === selectedAccountId) ?? accounts.data?.data[0];
    const wallet = useQuery({
        queryKey: dealerKeys.wallet(user?.id, account?.id ?? 0),
        queryFn: () => dealerApi.wallet(account!.id),
        enabled: Boolean(account),
        refetchInterval: 10000,
    });
    const transactions = useQuery({
        queryKey: dealerKeys.walletTransactions(user?.id, account?.id ?? 0, 1),
        queryFn: () => dealerApi.walletTransactions(account!.id, 1),
        enabled: Boolean(account),
    });

    if (isLoading || accounts.isPending) return <LoadingState />;
    if (!user || user.role !== "customer")
        return (
            <p className="text-sm text-muted-foreground">
                Vui lòng đăng nhập bằng tài khoản đại lý.
            </p>
        );
    if (accounts.isError)
        return (
            <ErrorState
                message={errorMessage(accounts.error)}
                retry={() => void accounts.refetch()}
            />
        );
    if (!account) return <EmptyState message="Chưa có tài khoản đại lý đang hoạt động." />;

    return (
        <div className="dealer-page-form space-y-6">
            <header>
                <p className="text-xs font-semibold tracking-[.14em] text-[#bc9151]">TÀI CHÍNH</p>
                <h1 className="dealer-page-title mt-2 text-[#092b5c]">Ví trả trước</h1>
                <p className="mt-3 text-sm leading-6 text-[#68758a]">
                    Số dư ví dùng để thanh toán đơn hàng đại lý.
                </p>
            </header>
            {(accounts.data?.data.length ?? 0) > 1 && (
                <label className="grid max-w-sm gap-2 text-sm font-medium">
                    Đại lý
                    <select
                        className="dealer-control rounded-xl border px-3"
                        value={account.id}
                        onChange={(event) => setSelectedAccountId(Number(event.target.value))}
                    >
                        {accounts.data?.data.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.code} · {item.legal_name}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            {wallet.isPending ? (
                <LoadingState />
            ) : wallet.isError ? (
                <p role="alert" className="text-sm text-red-700">
                    {errorMessage(wallet.error)}
                </p>
            ) : (
                <section className="max-w-2xl">
                    <div className="rounded-2xl bg-[#092b5c] p-6 text-white">
                        <WalletCards size={24} />
                        <p className="mt-6 text-sm text-white/70">Số dư khả dụng</p>
                        <p className="mt-1 text-3xl font-semibold">
                            {money(wallet.data.data.available_balance)}
                        </p>
                        <Link
                            to="/dealer/quick-order"
                            search={{ sku: "", reorder: 0 }}
                            className="dealer-action mt-5 rounded-xl border border-white/50 px-4 py-2.5 text-white hover:bg-white/10"
                        >
                            Đặt hàng nhanh
                        </Link>
                    </div>
                </section>
            )}
            <DealerWalletDepositRequestPanel
                key={account.id}
                accountId={account.id}
                userId={user.id}
            />
            <section className="rounded-2xl border bg-card p-5 sm:p-6">
                <h2 className="text-xl font-semibold text-[#092b5c]">Lịch sử biến động số dư</h2>
                {transactions.isPending ? (
                    <LoadingState />
                ) : transactions.isError ? (
                    <p role="alert" className="mt-4 text-sm text-red-700">
                        {errorMessage(transactions.error)}
                    </p>
                ) : transactions.data.data.length === 0 ? (
                    <p className="mt-4 text-sm text-muted-foreground">Chưa có giao dịch.</p>
                ) : (
                    <div className="mt-4 divide-y">
                        {transactions.data.data.map((transaction) => (
                            <div
                                key={transaction.id}
                                className="flex items-center justify-between gap-4 py-4 text-sm"
                            >
                                <div className="flex items-center gap-3">
                                    <span
                                        className={`grid size-8 place-items-center rounded-full ${transaction.direction === "credit" ? "bg-emerald-50 text-emerald-700" : "bg-rose-50 text-rose-700"}`}
                                    >
                                        {transaction.direction === "credit" ? (
                                            <ArrowDownLeft size={16} />
                                        ) : (
                                            <ArrowUpRight size={16} />
                                        )}
                                    </span>
                                    <span>
                                        <strong>
                                            {transaction.type === "order_debit"
                                                ? "Thanh toán đơn hàng"
                                                : transaction.type === "refund_credit"
                                                  ? "Hoàn tiền vào ví"
                                                  : "Nạp tiền vào ví"}
                                        </strong>
                                        <span className="dealer-meta block text-muted-foreground">
                                            {transaction.transaction_code}
                                        </span>
                                    </span>
                                </div>
                                <span
                                    className={
                                        transaction.direction === "credit"
                                            ? "font-semibold text-emerald-700"
                                            : "font-semibold text-rose-700"
                                    }
                                >
                                    {transaction.direction === "credit" ? "+" : "−"}
                                    {money(transaction.amount)}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </section>
        </div>
    );
}
