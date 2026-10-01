import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { ErrorState, LoadingState } from "@/components/common/AsyncState";
import { ProductAdminGuard, fieldClass } from "@/pages/admin/ProductAdminShared";
import { dealerApi, dealerKeys } from "./api";
import type { AdminDealerWalletTopUp } from "./types";
import { walletDate, walletMoney } from "./dealerWalletFormat";

const statusLabels: Record<string, string> = {
    initiating: "Đang khởi tạo",
    pending: "Chờ thanh toán",
    paid: "Đã thanh toán",
    expired: "Hết hạn",
    failed: "Thất bại",
    cancelled: "Đã hủy",
};

export function AdminDealerWalletTopUpsPage({ embedded = false }: { embedded?: boolean }) {
    const [status, setStatus] = useState("");
    const [search, setSearch] = useState("");
    const [from, setFrom] = useState("");
    const [to, setTo] = useState("");
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<AdminDealerWalletTopUp | null>(null);
    const filters = { status, search, from, to, page };
    const topUps = useQuery({
        queryKey: dealerKeys.adminTopUps(filters),
        queryFn: () => dealerApi.adminTopUps(filters),
    });
    const resetPage = () => setPage(1);

    return (
        <ProductAdminGuard>
            <div className="space-y-4">
                {!embedded && (
                    <h1 className="text-2xl font-semibold text-primary">Yêu cầu nạp tiền</h1>
                )}
                <p className="text-sm text-muted-foreground">
                    Yêu cầu PayOS được ghi vào ví khi cổng thanh toán xác nhận thành công.
                </p>
                <div className="grid gap-2 rounded-xl border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4">
                    <input
                        className={fieldClass}
                        aria-label="Tìm đại lý"
                        placeholder="Tên, mã hoặc SĐT đại lý"
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            resetPage();
                        }}
                    />
                    <select
                        className={fieldClass}
                        aria-label="Trạng thái"
                        value={status}
                        onChange={(event) => {
                            setStatus(event.target.value);
                            resetPage();
                        }}
                    >
                        <option value="">Tất cả trạng thái</option>
                        {Object.entries(statusLabels).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
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
                {topUps.isPending ? (
                    <LoadingState />
                ) : topUps.isError ? (
                    <ErrorState
                        message="Không thể tải dữ liệu. Vui lòng thử lại."
                        retry={() => void topUps.refetch()}
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border bg-card">
                        <table className="w-full min-w-[820px] text-left text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr>
                                    <th className="p-3">Thời gian</th>
                                    <th className="p-3">Đại lý</th>
                                    <th className="p-3 text-right">Số tiền yêu cầu</th>
                                    <th className="p-3">Phương thức</th>
                                    <th className="p-3">Mã yêu cầu</th>
                                    <th className="p-3">Trạng thái</th>
                                    <th className="p-3">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                {topUps.data.data.map((topUp) => (
                                    <tr key={topUp.id} className="border-t">
                                        <td className="p-3 whitespace-nowrap">
                                            {walletDate(topUp.created_at)}
                                        </td>
                                        <td className="p-3">
                                            {topUp.dealer_account ? (
                                                <Link
                                                    to="/admin/dealers/$id"
                                                    search={{ tab: "wallet" }}
                                                    params={{ id: String(topUp.dealer_account.id) }}
                                                    className="text-primary underline"
                                                >
                                                    {topUp.dealer_account.legal_name}
                                                </Link>
                                            ) : (
                                                "—"
                                            )}
                                        </td>
                                        <td className="p-3 text-right font-medium">
                                            {walletMoney(topUp.amount)}
                                        </td>
                                        <td className="p-3">PayOS</td>
                                        <td className="p-3 font-medium">{topUp.top_up_code}</td>
                                        <td className="p-3">{statusLabels[topUp.status]}</td>
                                        <td className="p-3">
                                            <button
                                                type="button"
                                                className="text-primary underline"
                                                onClick={() => setSelected(topUp)}
                                            >
                                                Xem
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {topUps.data.data.length === 0 && (
                            <p className="p-5 text-sm text-muted-foreground">
                                Chưa có yêu cầu nạp tiền.
                            </p>
                        )}
                    </div>
                )}
                {topUps.data && topUps.data.last_page > 1 && (
                    <div className="flex items-center justify-end gap-3 text-sm">
                        <button
                            type="button"
                            disabled={page <= 1}
                            onClick={() => setPage(page - 1)}
                        >
                            Trước
                        </button>
                        <span>
                            {page} / {topUps.data.last_page}
                        </span>
                        <button
                            type="button"
                            disabled={page >= topUps.data.last_page}
                            onClick={() => setPage(page + 1)}
                        >
                            Sau
                        </button>
                    </div>
                )}
                <Dialog
                    open={selected !== null}
                    onOpenChange={(open) => {
                        if (!open) setSelected(null);
                    }}
                >
                    <DialogContent className="max-h-[90vh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-xl">
                        <DialogHeader>
                            <DialogTitle>Chi tiết yêu cầu nạp tiền</DialogTitle>
                        </DialogHeader>
                        {selected && (
                            <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt className="text-muted-foreground">Mã yêu cầu</dt>
                                    <dd className="font-medium">{selected.top_up_code}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Trạng thái</dt>
                                    <dd>{statusLabels[selected.status]}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Đại lý</dt>
                                    <dd>{selected.dealer_account?.legal_name ?? "—"}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Số tiền</dt>
                                    <dd className="font-semibold">
                                        {walletMoney(selected.amount)}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Phương thức</dt>
                                    <dd>PayOS</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Thời gian tạo</dt>
                                    <dd>{walletDate(selected.created_at)}</dd>
                                </div>
                                <div className="sm:col-span-2">
                                    <dt className="text-muted-foreground">Mã tham chiếu PayOS</dt>
                                    <dd className="break-all">
                                        {selected.provider_reference ?? "—"}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Thanh toán lúc</dt>
                                    <dd>{walletDate(selected.paid_at)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Ghi ví lúc</dt>
                                    <dd>{walletDate(selected.completed_at)}</dd>
                                </div>
                            </dl>
                        )}
                    </DialogContent>
                </Dialog>
            </div>
        </ProductAdminGuard>
    );
}
