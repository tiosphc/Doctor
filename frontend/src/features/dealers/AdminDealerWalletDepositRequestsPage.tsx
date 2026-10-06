import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { toast } from "sonner";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { ErrorState, LoadingState } from "@/components/common/AsyncState";
import { fieldClass } from "@/pages/admin/ProductAdminShared";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { dealerApi, dealerKeys } from "./api";
import { walletDate, walletMoney } from "./dealerWalletFormat";

const statusLabels = {
    pending: { label: "Chờ xác nhận", className: "bg-amber-100 text-amber-800" },
    approved: { label: "Đã xác nhận", className: "bg-emerald-100 text-emerald-800" },
    rejected: { label: "Từ chối", className: "bg-rose-100 text-rose-800" },
} as const;

export function AdminDealerWalletDepositRequestsPage() {
    const client = useQueryClient();
    const [status, setStatus] = useState("");
    const [search, setSearch] = useState("");
    const [from, setFrom] = useState("");
    const [to, setTo] = useState("");
    const [page, setPage] = useState(1);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [proofUrl, setProofUrl] = useState<string | null>(null);
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState("");
    useEffect(() => {
        const id = Number(new URLSearchParams(window.location.search).get("request"));
        if (Number.isSafeInteger(id) && id > 0) setSelectedId(id);
    }, []);
    const filters = { status, search, from, to, page };
    const requests = useQuery({
        queryKey: dealerKeys.adminDepositRequests(filters),
        queryFn: () => dealerApi.adminDepositRequests(filters),
    });
    const detail = useQuery({
        queryKey: ["admin-dealer-wallet-deposit-request", selectedId],
        queryFn: () => dealerApi.adminDepositRequest(selectedId!),
        enabled: selectedId !== null,
    });
    const proof = useQuery({
        queryKey: ["admin-dealer-wallet-deposit-proof", selectedId],
        queryFn: () => dealerApi.adminDepositProof(selectedId!),
        enabled: selectedId !== null,
    });
    useEffect(() => {
        if (!proof.data) {
            setProofUrl(null);
            return;
        }
        const url = URL.createObjectURL(proof.data);
        setProofUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [proof.data]);
    const refresh = async () => {
        await Promise.all([
            client.invalidateQueries({ queryKey: ["admin-dealer-wallet-deposit-requests"] }),
            client.invalidateQueries({
                queryKey: ["admin-dealer-wallet-deposit-request", selectedId],
            }),
            client.invalidateQueries({ queryKey: ["admin-all-wallet-transactions"] }),
            client.invalidateQueries({ queryKey: ["admin-dealer-wallets"] }),
        ]);
    };
    const approve = useMutation({
        mutationFn: () => dealerApi.approveDepositRequest(selectedId!),
        onSuccess: async () => {
            toast.success("Đã xác nhận và nạp tiền vào ví.");
            await refresh();
        },
        onError: (error) => toast.error(errorMessage(error)),
    });
    const reject = useMutation({
        mutationFn: () => dealerApi.rejectDepositRequest(selectedId!, reason),
        onSuccess: async () => {
            setRejecting(false);
            setReason("");
            toast.success("Đã từ chối yêu cầu.");
            await refresh();
        },
        onError: (error) => toast.error(errorMessage(error)),
    });
    const item = detail.data?.data;

    return (
        <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
                Kiểm tra chứng từ trước khi xác nhận. Ví chỉ được cộng khi Admin phê duyệt.
            </p>
            <div className="grid gap-2 rounded-xl border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4">
                <input
                    className={fieldClass}
                    aria-label="Tìm đại lý hoặc mã yêu cầu"
                    placeholder="Tên, mã đại lý hoặc mã yêu cầu"
                    value={search}
                    onChange={(event) => {
                        setSearch(event.target.value);
                        setPage(1);
                    }}
                />
                <select
                    className={fieldClass}
                    aria-label="Trạng thái"
                    value={status}
                    onChange={(event) => {
                        setStatus(event.target.value);
                        setPage(1);
                    }}
                >
                    <option value="">Tất cả trạng thái</option>
                    {Object.entries(statusLabels).map(([key, value]) => (
                        <option key={key} value={key}>
                            {value.label}
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
                        setPage(1);
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
                        setPage(1);
                    }}
                />
            </div>
            {requests.isPending ? (
                <LoadingState />
            ) : requests.isError ? (
                <ErrorState
                    message={errorMessage(requests.error)}
                    retry={() => void requests.refetch()}
                />
            ) : (
                <div className="overflow-x-auto rounded-xl border bg-card">
                    <table className="w-full min-w-[920px] text-left text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th className="p-3">Mã yêu cầu</th>
                                <th className="p-3">Đại lý</th>
                                <th className="p-3">Tier</th>
                                <th className="p-3 text-right">Số tiền</th>
                                <th className="p-3">Chứng từ</th>
                                <th className="p-3">Ngày gửi</th>
                                <th className="p-3">Trạng thái</th>
                                <th className="p-3">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            {requests.data.data.map((request) => (
                                <tr
                                    key={request.id}
                                    className={`border-t ${request.status === "pending" ? "bg-amber-50/50" : ""}`}
                                >
                                    <td className="p-3 font-medium">{request.request_code}</td>
                                    <td className="p-3">
                                        <Link
                                            to="/admin/dealers/$id"
                                            search={{ tab: "wallet" }}
                                            params={{ id: String(request.dealer_account.id) }}
                                            className="text-primary underline"
                                        >
                                            {request.dealer_account.legal_name}
                                        </Link>
                                    </td>
                                    <td className="p-3">
                                        {request.dealer_account.tier?.name ?? "—"}
                                    </td>
                                    <td className="p-3 text-right font-semibold">
                                        {walletMoney(request.amount)}
                                    </td>
                                    <td className="p-3">Có ảnh</td>
                                    <td className="p-3 whitespace-nowrap">
                                        {walletDate(request.created_at)}
                                    </td>
                                    <td className="p-3">
                                        <span
                                            className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusLabels[request.status].className}`}
                                        >
                                            {statusLabels[request.status].label}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        <button
                                            className="text-primary underline"
                                            onClick={() => setSelectedId(request.id)}
                                        >
                                            Xem
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {requests.data.data.length === 0 && (
                        <p className="p-5 text-sm text-muted-foreground">Chưa có yêu cầu.</p>
                    )}
                </div>
            )}
            {requests.data && requests.data.last_page > 1 && (
                <div className="flex justify-end gap-3 text-sm">
                    <button disabled={page <= 1} onClick={() => setPage(page - 1)}>
                        Trước
                    </button>
                    <span>
                        {page}/{requests.data.last_page}
                    </span>
                    <button
                        disabled={page >= requests.data.last_page}
                        onClick={() => setPage(page + 1)}
                    >
                        Sau
                    </button>
                </div>
            )}
            <Dialog
                open={selectedId !== null}
                onOpenChange={(open) => {
                    if (!open && !approve.isPending && !reject.isPending) {
                        setSelectedId(null);
                        setRejecting(false);
                        setReason("");
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl overflow-y-auto rounded-xl">
                    <DialogHeader>
                        <DialogTitle>Chi tiết yêu cầu nạp tiền</DialogTitle>
                    </DialogHeader>
                    {detail.isPending ? (
                        <LoadingState />
                    ) : detail.isError ? (
                        <ErrorState
                            message={errorMessage(detail.error)}
                            retry={() => void detail.refetch()}
                        />
                    ) : (
                        item && (
                            <div className="space-y-4 text-sm">
                                <dl className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <dt className="text-muted-foreground">Mã yêu cầu</dt>
                                        <dd className="font-semibold">{item.request_code}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Trạng thái</dt>
                                        <dd>{statusLabels[item.status].label}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Đại lý</dt>
                                        <dd>
                                            {item.dealer_account.legal_name} ·{" "}
                                            {item.dealer_account.code}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Số dư ví hiện tại</dt>
                                        <dd>{walletMoney(item.wallet_balance ?? "0")}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Số tiền yêu cầu</dt>
                                        <dd className="font-semibold">
                                            {walletMoney(item.amount)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Ngày gửi</dt>
                                        <dd>{walletDate(item.created_at)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Mã giao dịch</dt>
                                        <dd>{item.transaction_reference ?? "—"}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Ghi chú</dt>
                                        <dd>{item.note ?? "—"}</dd>
                                    </div>
                                    {item.rejection_reason && (
                                        <div className="sm:col-span-2">
                                            <dt className="text-muted-foreground">Lý do từ chối</dt>
                                            <dd className="text-rose-700">
                                                {item.rejection_reason}
                                            </dd>
                                        </div>
                                    )}
                                </dl>
                                <div>
                                    <p className="mb-2 font-medium">Chứng từ thanh toán</p>
                                    {proof.isPending ? (
                                        <p>Đang tải ảnh...</p>
                                    ) : proof.isError ? (
                                        <p role="alert" className="text-rose-700">
                                            {errorMessage(proof.error)}
                                        </p>
                                    ) : (
                                        proofUrl && (
                                            <a href={proofUrl} target="_blank" rel="noreferrer">
                                                <img
                                                    src={proofUrl}
                                                    alt={`Chứng từ ${item.request_code}`}
                                                    className="max-h-[60vh] w-full rounded-xl border object-contain"
                                                />
                                            </a>
                                        )
                                    )}
                                </div>
                                {item.status === "pending" && (
                                    <div className="space-y-3 border-t pt-4">
                                        {rejecting && (
                                            <label className="grid gap-1">
                                                Lý do từ chối *
                                                <textarea
                                                    className="min-h-20 rounded-lg border p-2"
                                                    maxLength={2000}
                                                    value={reason}
                                                    onChange={(event) =>
                                                        setReason(event.target.value)
                                                    }
                                                />
                                                {firstFieldErrors(reject.error)[
                                                    "rejection_reason"
                                                ] && (
                                                    <span className="text-xs text-rose-700">
                                                        {
                                                            firstFieldErrors(reject.error)[
                                                                "rejection_reason"
                                                            ]
                                                        }
                                                    </span>
                                                )}
                                            </label>
                                        )}
                                        <div className="flex flex-wrap justify-end gap-2">
                                            <button
                                                className="rounded-lg border px-4 py-2"
                                                disabled={approve.isPending || reject.isPending}
                                                onClick={() => {
                                                    setRejecting(true);
                                                    if (rejecting && reason.trim()) reject.mutate();
                                                }}
                                            >
                                                {rejecting ? "Xác nhận từ chối" : "Từ chối"}
                                            </button>
                                            {!rejecting && (
                                                <button
                                                    className="rounded-lg bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50"
                                                    disabled={approve.isPending || reject.isPending}
                                                    onClick={() => {
                                                        if (
                                                            window.confirm(
                                                                `Xác nhận nạp ${walletMoney(item.amount)} cho ${item.dealer_account.legal_name}?`,
                                                            )
                                                        )
                                                            approve.mutate();
                                                    }}
                                                >
                                                    {approve.isPending
                                                        ? "Đang xử lý..."
                                                        : "Xác nhận & nạp tiền"}
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
