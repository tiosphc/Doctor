import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ExternalLink, QrCode } from "lucide-react";
import { useRef, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { errorMessage } from "@/services/api";
import { dealerApi, dealerKeys } from "./api";
import type { DealerWalletTopUp } from "./types";

const money = (value: string) =>
    new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(Number(value));
const statusLabel = (status: DealerWalletTopUp["status"]) =>
    ({
        initiating: "Đang tạo",
        pending: "Đang chờ thanh toán",
        paid: "Đã xác nhận",
        expired: "Đã hết hạn",
        failed: "Thất bại",
        cancelled: "Đã hủy",
    })[status];

export function DealerWalletTopUpPanel({ accountId }: { accountId: number }) {
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const [amount, setAmount] = useState("");
    const [created, setCreated] = useState<DealerWalletTopUp | null>(null);
    const [page, setPage] = useState(1);
    const operationKey = useRef<string | null>(null);
    const topUps = useQuery({
        queryKey: dealerKeys.walletTopUps(user?.id, accountId, page),
        queryFn: () => dealerApi.walletTopUps(accountId, page),
        refetchInterval: (query) =>
            query.state.data?.data.some(
                (topUp) => topUp.status === "pending" || topUp.status === "initiating",
            )
                ? 5000
                : false,
    });
    const create = useMutation({
        mutationFn: () => {
            operationKey.current ??= crypto.randomUUID();
            return dealerApi.createWalletTopUp(accountId, Number(amount), operationKey.current);
        },
        onSuccess: ({ data }) => {
            setCreated(data);
            setPage(1);
            operationKey.current = null;
            void queryClient.invalidateQueries({
                queryKey: ["dealer-wallet-top-ups", user?.id, accountId],
            });
        },
    });
    const latestCreated = topUps.data?.data.find((topUp) => topUp.id === created?.id) ?? created;
    const refresh = useMutation({
        mutationFn: (topUpId: number) => dealerApi.refreshWalletTopUp(accountId, topUpId),
        onSuccess: () => {
            void queryClient.invalidateQueries({
                queryKey: ["dealer-wallet-top-ups", user?.id, accountId],
            });
            void queryClient.invalidateQueries({
                queryKey: dealerKeys.wallet(user?.id, accountId),
            });
            void queryClient.invalidateQueries({
                queryKey: dealerKeys.walletTransactions(user?.id, accountId, 1),
            });
        },
    });

    return (
        <section className="rounded-2xl border bg-card p-5 sm:p-6">
            <div className="flex items-center gap-3">
                <QrCode className="text-[#092b5c]" size={22} />
                <h2 className="text-xl font-semibold text-[#092b5c]">Nạp tiền bằng mã QR</h2>
            </div>
            <p className="mt-2 text-[15px] leading-6 text-muted-foreground">
                Số dư chỉ tăng sau khi PayOS xác nhận đã thanh toán qua webhook. Trang quay về không
                cộng tiền.
            </p>
            <form
                className="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (
                        Number.isInteger(Number(amount)) &&
                        Number(amount) >= 2000 &&
                        Number(amount) <= 1000000000
                    ) {
                        create.mutate();
                    }
                }}
            >
                <label className="min-w-0 flex-1 text-sm font-semibold text-[#092b5c]">
                    Số tiền nạp (VND)
                    <input
                        type="number"
                        min={2000}
                        max={1000000000}
                        step={1}
                        required
                        value={amount}
                        onChange={(event) => {
                            setAmount(event.target.value);
                            operationKey.current = null;
                            setCreated(null);
                        }}
                        className="dealer-control mt-2 block w-full rounded-xl border border-[#d8dee8] bg-white px-3 focus:border-[#092b5c] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#092b5c]"
                    />
                </label>
                <button
                    type="submit"
                    disabled={create.isPending}
                    className="dealer-action rounded-xl bg-[#092b5c] px-5 text-white disabled:opacity-50"
                >
                    {create.isPending ? "Đang tạo QR..." : "Tạo mã QR"}
                </button>
            </form>
            {create.isError && (
                <p role="alert" className="mt-3 text-sm text-red-700">
                    {errorMessage(create.error)}
                </p>
            )}
            {latestCreated && (
                <div className="mt-5 rounded-xl border border-[#ead9bc] bg-[#fffaf1] p-4 text-sm">
                    <p className="font-semibold text-[#092b5c]">
                        {latestCreated.top_up_code} · {money(latestCreated.amount)} ·{" "}
                        {statusLabel(latestCreated.status)}
                    </p>
                    {latestCreated.status !== "paid" && (
                        <p className="mt-1 text-muted-foreground">Số dư ví chưa thay đổi.</p>
                    )}
                    {(latestCreated.status === "pending" ||
                        latestCreated.status === "initiating") &&
                        latestCreated.checkout_url && (
                            <a
                                href={latestCreated.checkout_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-3 inline-flex items-center gap-2 font-semibold text-[#092b5c] underline"
                            >
                                Mở mã QR PayOS <ExternalLink size={15} />
                            </a>
                        )}
                </div>
            )}
            <h3 className="mt-6 text-lg font-semibold text-[#092b5c]">Yêu cầu nạp gần đây</h3>
            {topUps.isPending ? (
                <p className="mt-2 text-sm text-muted-foreground">Đang tải...</p>
            ) : topUps.isError ? (
                <p role="alert" className="mt-2 text-sm text-red-700">
                    {errorMessage(topUps.error)}
                </p>
            ) : topUps.data.data.length === 0 ? (
                <p className="mt-2 text-sm text-muted-foreground">Chưa có yêu cầu nạp.</p>
            ) : (
                <div className="mt-2 divide-y">
                    {topUps.data.data.map((topUp) => (
                        <div
                            key={topUp.id}
                            className="flex flex-wrap items-center justify-between gap-3 py-4 text-[15px]"
                        >
                            <span>
                                <strong>{topUp.top_up_code}</strong> · {money(topUp.amount)} ·{" "}
                                {topUp.status === "paid"
                                    ? "Đã xác nhận"
                                    : topUp.status === "expired"
                                      ? "Đã hết hạn"
                                      : topUp.status === "failed"
                                        ? "Thất bại"
                                        : topUp.status === "cancelled"
                                          ? "Đã hủy"
                                          : "Đang chờ thanh toán"}
                            </span>
                            {(topUp.status === "pending" || topUp.status === "initiating") &&
                                topUp.checkout_url && (
                                    <a
                                        href={topUp.checkout_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="font-medium text-[#092b5c] underline"
                                    >
                                        Mở QR
                                    </a>
                                )}
                            {topUp.status !== "paid" && (
                                <button
                                    type="button"
                                    disabled={refresh.isPending}
                                    onClick={() => refresh.mutate(topUp.id)}
                                    className="font-medium text-[#092b5c] underline disabled:opacity-50"
                                >
                                    Kiểm tra PayOS
                                </button>
                            )}
                        </div>
                    ))}
                </div>
            )}
            {refresh.isError && (
                <p role="alert" className="mt-2 text-sm text-red-700">
                    {errorMessage(refresh.error)}
                </p>
            )}
            {topUps.data && topUps.data.last_page > 1 && (
                <div className="mt-4 flex items-center justify-end gap-3 text-sm">
                    <button
                        type="button"
                        disabled={page <= 1}
                        onClick={() => setPage(page - 1)}
                        className="dealer-action rounded-lg border px-3 disabled:opacity-40"
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
                        className="dealer-action rounded-lg border px-3 disabled:opacity-40"
                    >
                        Sau
                    </button>
                </div>
            )}
        </section>
    );
}
