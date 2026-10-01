import { Link, Navigate } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { EmptyState, ErrorState, LoadingState } from "@/components/common/AsyncState";
import { useAuth } from "@/contexts/AuthContext";
import { errorMessage } from "@/services/api";
import { dealerApi, dealerKeys } from "./api";
import { DealerAutoTierProgress } from "./DealerAutoTierProgress";

export function DealerProfilePage() {
    const { user, isLoading } = useAuth();
    const query = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled: Boolean(user) && user?.role === "customer",
    });
    if (isLoading) return <LoadingState />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== "customer") return <Navigate to="/account" />;
    if (query.isPending) return <LoadingState />;
    if (query.isError)
        return (
            <ErrorState message={errorMessage(query.error)} retry={() => void query.refetch()} />
        );
    if (!query.data.data.length) {
        return (
            <div className="mx-auto max-w-4xl space-y-5 px-4 py-8 sm:px-6">
                <EmptyState message="Bạn chưa có tài khoản đại lý đang hoạt động." />
                <Link
                    to="/dealer/apply"
                    className="inline-flex text-sm font-medium text-primary underline"
                >
                    Xem trạng thái đăng ký
                </Link>
            </div>
        );
    }
    return (
        <div className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6">
            <header>
                <p className="label-luxury">Hồ sơ B2B</p>
                <h1 className="mt-2 text-3xl text-primary">Đại lý của tôi</h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Thông tin cơ sở và quyền thành viên đã được duyệt.
                </p>
            </header>
            <div className="max-w-3xl">
                {query.data.data.slice(0, 1).map((dealer) => (
                    <section key={dealer.id} className="rounded-xl border bg-card p-5 sm:p-6">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="text-xs uppercase tracking-widest text-muted-foreground">
                                    {dealer.code}
                                </p>
                                <h2 className="mt-1 text-xl text-primary">{dealer.legal_name}</h2>
                            </div>
                            <span className="rounded-full bg-green-50 px-3 py-1 text-xs text-green-800">
                                Đang hoạt động
                            </span>
                        </div>
                        <DealerTierSummary dealerId={dealer.id} userId={user.id} />
                        <div className="mt-4">
                            <DealerAutoTierProgress dealerId={dealer.id} userId={user.id} />
                        </div>
                        <Link
                            to="/dealer/products"
                            className="mt-4 inline-flex text-sm font-medium text-primary underline underline-offset-4"
                        >
                            Xem sản phẩm và giá đại lý
                        </Link>
                        <div className="mt-3 flex flex-wrap gap-4 text-sm font-medium text-primary">
                            <Link
                                to="/dealer/quick-order"
                                search={{ sku: "", reorder: 0 }}
                                className="underline"
                            >
                                Đặt hàng nhanh
                            </Link>
                            <Link to="/dealer/orders" className="underline">
                                Đơn hàng đại lý
                            </Link>
                        </div>
                        <dl className="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-muted-foreground">Vai trò thành viên</dt>
                                <dd className="font-medium">
                                    {dealer.membership_role === "owner"
                                        ? "Chủ sở hữu"
                                        : dealer.membership_role}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Người liên hệ</dt>
                                <dd>{dealer.contact_name}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Điện thoại</dt>
                                <dd>{dealer.phone}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Email</dt>
                                <dd>{dealer.email}</dd>
                            </div>
                            {dealer.tax_code && (
                                <div>
                                    <dt className="text-muted-foreground">Mã số thuế</dt>
                                    <dd>{dealer.tax_code}</dd>
                                </div>
                            )}
                            <div className="sm:col-span-2">
                                <dt className="text-muted-foreground">Địa chỉ</dt>
                                <dd>
                                    {[
                                        dealer.billing_address_line1,
                                        dealer.billing_address_line2,
                                        dealer.city,
                                        dealer.province,
                                        dealer.country,
                                    ]
                                        .filter(Boolean)
                                        .join(", ")}
                                </dd>
                            </div>
                        </dl>
                    </section>
                ))}
            </div>
        </div>
    );
}

function DealerTierSummary({ dealerId, userId }: { dealerId: number; userId: number }) {
    const query = useQuery({
        queryKey: dealerKeys.tier(userId, dealerId),
        queryFn: () => dealerApi.tier(dealerId),
    });
    if (query.isPending)
        return <p className="mt-4 text-sm text-muted-foreground">Đang tải Dealer Tier...</p>;
    if (query.isError)
        return <p className="mt-4 text-sm text-red-700">{errorMessage(query.error)}</p>;
    const tier = query.data.data;
    return (
        <div className="mt-4 rounded-lg bg-primary/5 p-4 text-sm">
            <p className="font-medium text-primary">
                Dealer Tier: {tier.effective_tier?.name ?? "Chưa được gán"}
            </p>
            {tier.effective_tier && (
                <dl className="mt-3 grid gap-3 border-t border-primary/10 pt-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-muted-foreground">Ngày bắt đầu tier</dt>
                        <dd className="mt-0.5 font-medium text-primary">
                            {tier.effective_at
                                ? new Date(tier.effective_at).toLocaleDateString("vi-VN")
                                : "Chưa có dữ liệu"}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Ngày hết hạn tier</dt>
                        <dd className="mt-0.5 font-medium text-primary">
                            {tier.expires_at
                                ? new Date(tier.expires_at).toLocaleDateString("vi-VN")
                                : tier.source === "manual_override"
                                  ? "Khi Admin hủy ngoại lệ"
                                  : "Chưa có dữ liệu"}
                        </dd>
                    </div>
                </dl>
            )}
            {tier.source === "manual_override" && (
                <p className="mt-1 text-muted-foreground">
                    Tier cơ sở: {tier.base_tier?.name ?? "—"}.
                </p>
            )}
        </div>
    );
}
