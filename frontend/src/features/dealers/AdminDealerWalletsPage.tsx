import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ErrorState, LoadingState } from "@/components/common/AsyncState";
import { ProductAdminGuard, fieldClass } from "@/pages/admin/ProductAdminShared";
import { dealerApi, dealerKeys } from "./api";
import { AdminDealerWalletTopUpsPage } from "./AdminDealerWalletTopUpsPage";
import { DealerWalletTransactionsTable } from "./DealerWalletTransactionsTable";
import { walletMoney } from "./dealerWalletFormat";

type PageTab = "transactions" | "requests";

export function AdminDealerWalletsPage() {
    const [tab, setTab] = useState<PageTab>("transactions");
    const [search, setSearch] = useState("");
    const [dealerId, setDealerId] = useState<number | "">("");
    const [dealerSearch, setDealerSearch] = useState("");
    const [type, setType] = useState("");
    const [from, setFrom] = useState("");
    const [to, setTo] = useState("");
    const [page, setPage] = useState(1);
    const filters = { search, dealer_id: dealerId, type, from, to, page };
    const transactions = useQuery({
        queryKey: ["admin-all-wallet-transactions", filters],
        queryFn: () => dealerApi.adminAllWalletTransactions(filters),
        enabled: tab === "transactions",
    });
    const dealers = useQuery({
        queryKey: dealerKeys.adminList({ page: 1, search: dealerSearch }),
        queryFn: () => dealerApi.adminList({ page: 1, search: dealerSearch }),
        enabled: tab === "transactions",
    });
    const pendingTopUps = useQuery({
        queryKey: dealerKeys.adminTopUps({ status: "pending", page: 1 }),
        queryFn: () => dealerApi.adminTopUps({ status: "pending", page: 1 }),
    });
    const resetPage = () => setPage(1);

    return (
        <ProductAdminGuard>
            <div className="space-y-5">
                <header>
                    <p className="label-luxury">Khách hàng & đại lý</p>
                    <h1 className="mt-2 text-3xl text-primary">Ví đại lý</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Quản lý toàn bộ giao dịch và yêu cầu nạp tiền của đại lý.
                    </p>
                </header>
                <div className="flex gap-2 overflow-x-auto border-b pb-2 text-sm" role="tablist">
                    <button
                        type="button"
                        role="tab"
                        aria-selected={tab === "transactions"}
                        className={`whitespace-nowrap rounded-md px-3 py-2 ${tab === "transactions" ? "bg-primary text-primary-foreground" : "hover:bg-muted"}`}
                        onClick={() => setTab("transactions")}
                    >
                        Tất cả giao dịch
                    </button>
                    <button
                        type="button"
                        role="tab"
                        aria-selected={tab === "requests"}
                        className={`whitespace-nowrap rounded-md px-3 py-2 ${tab === "requests" ? "bg-primary text-primary-foreground" : "hover:bg-muted"}`}
                        onClick={() => setTab("requests")}
                    >
                        Yêu cầu nạp tiền
                        {pendingTopUps.data && pendingTopUps.data.total > 0
                            ? ` (${pendingTopUps.data.total})`
                            : ""}
                    </button>
                </div>
                {tab === "requests" ? (
                    <AdminDealerWalletTopUpsPage embedded />
                ) : (
                    <div className="space-y-4">
                        {transactions.isPending ? (
                            <LoadingState />
                        ) : transactions.isError ? (
                            <ErrorState
                                message="Không thể tải dữ liệu. Vui lòng thử lại."
                                retry={() => void transactions.refetch()}
                            />
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                {[
                                    ["Tổng số dư ví", transactions.data.summary.total_balance],
                                    ["Tổng tiền đã nạp", transactions.data.summary.total_deposited],
                                    ["Tổng tiền đã chi", transactions.data.summary.total_spent],
                                    ["Tổng tiền hoàn", transactions.data.summary.total_refunded],
                                ].map(([label, value]) => (
                                    <div key={label} className="rounded-xl border bg-card p-4">
                                        <p className="text-xs text-muted-foreground">{label}</p>
                                        <p className="mt-1 text-lg font-semibold text-primary">
                                            {walletMoney(value ?? "0")}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}
                        <div className="grid gap-2 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-3">
                            <input
                                className={fieldClass}
                                aria-label="Tìm giao dịch hoặc đại lý"
                                placeholder="Tên, mã, SĐT đại lý hoặc mã giao dịch"
                                value={search}
                                onChange={(event) => {
                                    setSearch(event.target.value);
                                    resetPage();
                                }}
                            />
                            <div className="flex min-w-0 gap-2">
                                <input
                                    className={`${fieldClass} min-w-0 flex-1`}
                                    aria-label="Tìm đại lý để lọc"
                                    placeholder="Tìm đại lý"
                                    value={dealerSearch}
                                    onChange={(event) => {
                                        setDealerSearch(event.target.value);
                                        setDealerId("");
                                        resetPage();
                                    }}
                                />
                                <select
                                    className={`${fieldClass} min-w-0 flex-1`}
                                    aria-label="Đại lý"
                                    value={dealerId}
                                    onChange={(event) => {
                                        setDealerId(
                                            event.target.value ? Number(event.target.value) : "",
                                        );
                                        resetPage();
                                    }}
                                >
                                    <option value="">Tất cả đại lý</option>
                                    {dealers.data?.data.map((dealer) => (
                                        <option key={dealer.id} value={dealer.id}>
                                            {dealer.code} · {dealer.legal_name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <select
                                className={fieldClass}
                                aria-label="Loại giao dịch"
                                value={type}
                                onChange={(event) => {
                                    setType(event.target.value);
                                    resetPage();
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
                                max={to || undefined}
                                onChange={(event) => {
                                    setFrom(event.target.value);
                                    resetPage();
                                }}
                            />
                            <input
                                className={fieldClass}
                                type="date"
                                aria-label="Đến ngày"
                                value={to}
                                min={from || undefined}
                                onChange={(event) => {
                                    setTo(event.target.value);
                                    resetPage();
                                }}
                            />
                        </div>
                        {!transactions.isPending && !transactions.isError && (
                            <DealerWalletTransactionsTable
                                transactions={transactions.data.data}
                                showDealer
                            />
                        )}
                        {transactions.data && transactions.data.last_page > 1 && (
                            <div className="flex items-center justify-end gap-3 text-sm">
                                <button
                                    type="button"
                                    disabled={page <= 1}
                                    onClick={() => setPage(page - 1)}
                                >
                                    Trước
                                </button>
                                <span>
                                    {page} / {transactions.data.last_page}
                                </span>
                                <button
                                    type="button"
                                    disabled={page >= transactions.data.last_page}
                                    onClick={() => setPage(page + 1)}
                                >
                                    Sau
                                </button>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </ProductAdminGuard>
    );
}
