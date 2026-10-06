import { Link } from "@tanstack/react-router";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect } from "react";
import { LoadingState } from "@/components/common/AsyncState";
import { useAuth } from "@/contexts/AuthContext";
import { errorMessage } from "@/services/api";
import { dealerApi, dealerKeys } from "./api";

export function DealerWalletTopUpResultPage({
    accountId,
    topUpId,
}: {
    accountId: number;
    topUpId: number;
}) {
    const { user, isLoading } = useAuth();
    const queryClient = useQueryClient();
    const topUp = useQuery({
        queryKey: dealerKeys.walletTopUp(user?.id, accountId, topUpId),
        queryFn: () => dealerApi.walletTopUp(accountId, topUpId),
        enabled: Boolean(user),
        refetchInterval: (query) =>
            ["paid", "expired", "failed", "cancelled"].includes(query.state.data?.data.status ?? "")
                ? false
                : 3000,
    });
    const wallet = useQuery({
        queryKey: dealerKeys.wallet(user?.id, accountId),
        queryFn: () => dealerApi.wallet(accountId),
        enabled: Boolean(user) && topUp.data?.data.status === "paid",
    });

    useEffect(() => {
        if (topUp.data?.data.status === "paid") {
            void queryClient.invalidateQueries({
                queryKey: dealerKeys.wallet(user?.id, accountId),
            });
            void queryClient.invalidateQueries({
                queryKey: dealerKeys.walletTransactions(user?.id, accountId, 1),
            });
            void queryClient.invalidateQueries({
                queryKey: ["dealer-wallet-top-ups", user?.id, accountId],
            });
        }
    }, [topUp.data?.data.status, user?.id, accountId, queryClient]);

    if (isLoading) return <LoadingState />;
    if (!user)
        return (
            <p className="text-sm text-muted-foreground">Đăng nhập để xem trạng thái nạp tiền.</p>
        );
    if (topUp.isPending) return <LoadingState />;
    if (topUp.isError)
        return (
            <p role="alert" className="text-sm text-red-700">
                {errorMessage(topUp.error)}
            </p>
        );

    const paid = topUp.data.data.status === "paid";
    const pending = ["pending", "initiating"].includes(topUp.data.data.status);

    return (
        <div className="mx-auto max-w-xl rounded-2xl border bg-card p-6 sm:p-8">
            <p className="text-xs font-semibold tracking-[.14em] text-[#bc9151]">VÍ ĐẠI LÝ</p>
            <h1 className="dealer-page-title mt-2 text-[#092b5c]">
                {paid
                    ? "Đã xác nhận nạp tiền"
                    : pending
                      ? "Đang chờ xác nhận thanh toán"
                      : `Yêu cầu ${topUp.data.data.status}`}
            </h1>
            <p className="mt-4 text-sm leading-6 text-muted-foreground">
                {paid
                    ? "Khoản nạp trước đây đã được ghi vào ví."
                    : "Đây là trạng thái của yêu cầu trước đây. Để gửi yêu cầu nạp tiền mới, vào trang ví đại lý và tải chứng từ thanh toán."}
            </p>
            <p className="mt-4 text-lg font-semibold text-[#092b5c]">
                {new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(
                    Number(topUp.data.data.amount),
                )}
            </p>
            {paid && wallet.data && (
                <p className="mt-2 text-sm text-emerald-700">
                    Số dư hiện tại:{" "}
                    {new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(
                        Number(wallet.data.data.balance),
                    )}
                </p>
            )}
            <div className="mt-6 flex flex-wrap gap-3">
                <Link
                    to="/dealer/top-up"
                    className="dealer-action rounded-xl bg-[#092b5c] px-4 py-2.5 text-white"
                >
                    Về ví đại lý
                </Link>
            </div>
        </div>
    );
}
