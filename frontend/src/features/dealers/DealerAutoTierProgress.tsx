import { useQuery } from "@tanstack/react-query";
import { errorMessage } from "@/services/api";
import { dealerApi, dealerKeys } from "./api";

const money = (value: string) =>
    `${new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 0 }).format(Number(value))} ₫`;

export function DealerAutoTierProgress({
    dealerId,
    userId,
    admin = false,
}: {
    dealerId: number;
    userId?: number;
    admin?: boolean;
}) {
    const query = useQuery({
        queryKey: admin
            ? dealerKeys.adminAutoTier(dealerId)
            : dealerKeys.autoTier(userId, dealerId),
        queryFn: () => (admin ? dealerApi.adminAutoTier(dealerId) : dealerApi.autoTier(dealerId)),
    });
    if (query.isPending)
        return <p className="text-sm text-muted-foreground">Đang tải tiến độ Tier...</p>;
    if (query.isError) return <p className="text-sm text-red-700">{errorMessage(query.error)}</p>;
    const progress = query.data.data;
    return (
        <div className="space-y-2 rounded-lg border bg-card p-4 text-sm">
            {progress.active_override && (
                <p>Tier hiệu lực theo ngoại lệ: {progress.effective_tier?.name ?? "Chưa có"}.</p>
            )}
            {admin && progress.would_change && (
                <p className="text-amber-700">
                    Hướng thay đổi:{" "}
                    {progress.direction === "upgrade"
                        ? "nâng Tier"
                        : progress.direction === "downgrade"
                          ? "hạ Tier"
                          : "đổi Tier cùng thứ tự"}
                    .
                </p>
            )}
            {admin && ["missing", "mismatch"].includes(progress.history_status) && (
                <p className="text-amber-700">
                    Lịch sử Tier cần kiểm tra: {progress.history_status}.
                </p>
            )}
            <p className="font-medium text-primary">Tiến độ Tier theo doanh thu 3 tháng gần nhất</p>
            <p>
                Doanh thu ròng kỳ {progress.revenue_period_start} – {progress.revenue_period_end}:{" "}
                <strong>{money(progress.net_revenue)}</strong>
            </p>
            <p className="text-muted-foreground">
                Đã thanh toán: {money(progress.settled_amount)} · Đã hoàn tiền:{" "}
                {money(progress.refunded_amount)} · Hàng trả chưa hoàn tiền:{" "}
                {money(progress.returned_amount)}
            </p>
            <p>Nâng hạng: Tự động ngay khi đủ điều kiện.</p>
            <p>
                Xét hạ hạng tiếp theo:{" "}
                {new Date(`${progress.next_evaluation_at}T00:00:00`).toLocaleDateString("vi-VN")},
                dựa trên doanh thu 3 tháng gần nhất.
            </p>
            <p>Tier cơ sở hiện tại: {progress.current_tier?.name ?? "Chưa gán"}</p>
            {progress.next_tier && progress.remaining_to_next && (
                <p>
                    Còn {money(progress.remaining_to_next)} để đạt {progress.next_tier.name}.
                </p>
            )}
            {!progress.next_tier && progress.target_tier && <p>Đã đạt ngưỡng Tier cao nhất.</p>}
            {!progress.enabled && <p className="text-amber-700">Tự động xét Tier đang tắt.</p>}
            {progress.reason === "rules_invalid" && (
                <p className="text-amber-700">Quy tắc Tier chưa được cấu hình hợp lệ.</p>
            )}
            {progress.reason === "account_inactive" && (
                <p className="text-muted-foreground">
                    Tài khoản hiện không được tự động thay đổi Tier.
                </p>
            )}
            {progress.reason === "override_active" && (
                <p className="text-muted-foreground">
                    Ngoại lệ Tier thủ công đang có hiệu lực; kỳ này không thay đổi Tier cơ sở.
                </p>
            )}
            {admin && progress.would_change && (
                <p className="text-amber-700">
                    Tier dự kiến: {progress.target_tier?.name}.{" "}
                    {progress.direction === "upgrade"
                        ? "Hệ thống nâng hạng ngay khi doanh thu hợp lệ đạt ngưỡng."
                        : "Hệ thống xét hạ hạng vào cuối tháng nếu không có ngoại lệ thủ công."}
                </p>
            )}
        </div>
    );
}
