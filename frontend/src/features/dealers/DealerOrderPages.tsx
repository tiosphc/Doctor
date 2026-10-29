import { SalesOrderStatusBadge } from "@/components/common/SalesOrderStatusBadge";
import { useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, Navigate } from "@tanstack/react-router";
import { EmptyState, ErrorState, LoadingState } from "@/components/common/AsyncState";
import { salesOrderSourceLabel, salesOrderStatusLabel } from "@/components/common/salesOrderStatus";
import { CustomerReturnSection } from "@/components/CustomerReturnSection";
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { useAuth } from "@/contexts/AuthContext";
import { ApiError, errorMessage } from "@/services/api";
import { formatProductQuantity } from "@/lib/productQuantity";
import { dealerApi, dealerKeys } from "./api";
import { DealerOrdersList } from "./DealerOrdersList";
import type { DealerOrder } from "./types";

const money = (value: string) =>
    new Intl.NumberFormat("vi-VN", {
        style: "currency",
        currency: "VND",
    }).format(Number(value));
function cancellationError(error: unknown): string {
    if (error instanceof ApiError) {
        if (error.code === "ORDER_INVALID_STATE")
            return "Đơn hàng đã thay đổi trạng thái hoặc đã xuất hàng. Vui lòng tải lại trang.";
        if (error.code === "DEALER_WALLET_REFUND_REQUIRED")
            return "Đơn hàng có khoản thanh toán cần quản trị viên kiểm tra trước khi hủy.";
        if (error.code === "PAYMENT_LEGACY_STATUS_REQUIRES_REVIEW")
            return "Thông tin thanh toán cần quản trị viên kiểm tra trước khi hủy.";
    }
    return errorMessage(error);
}

function Access({ children }: { children: React.ReactNode }) {
    const { user, isLoading } = useAuth();
    if (isLoading) return <LoadingState />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== "customer") return <Navigate to="/account" />;
    return children;
}

export function DealerOrdersPage() {
    const { user } = useAuth();
    const accounts = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled: user?.role === "customer",
    });
    const selected = accounts.data?.data[0];
    return (
        <Access>
            <main className="dealer-page-wide space-y-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="dealer-page-title text-primary">Đơn hàng đại lý</h1>
                        <p className="mt-1.5 text-[15px] leading-6 text-muted-foreground">
                            Theo dõi và quản lý đơn hàng của bạn và các thành viên.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2 max-sm:w-full">
                        <Link
                            to="/dealer/import-orders"
                            className="dealer-action rounded-md border px-4 py-2 text-primary hover:bg-accent max-sm:flex-1"
                        >
                            Nhập Excel
                        </Link>
                        <Link
                            to="/dealer/quick-order"
                            search={{ sku: "", reorder: 0 }}
                            className="dealer-action rounded-md bg-primary px-4 py-2 text-primary-foreground hover:bg-primary/90 max-sm:flex-1"
                        >
                            Đặt hàng nhanh
                        </Link>
                    </div>
                </header>
                {accounts.isPending ? (
                    <LoadingState />
                ) : accounts.isError ? (
                    <ErrorState
                        message={errorMessage(accounts.error)}
                        retry={() => void accounts.refetch()}
                    />
                ) : !selected ? (
                    <EmptyState message="Bạn chưa có tài khoản đại lý đang hoạt động." />
                ) : (
                    <DealerOrdersList accountId={selected.id} userId={user?.id} />
                )}
            </main>
        </Access>
    );
}
function OrderBody({ order }: { order: DealerOrder }) {
    return (
        <div className="space-y-5">
            <div className="grid gap-4 sm:grid-cols-2">
                <section className="rounded-xl border bg-card p-5">
                    <h2 className="dealer-section-title text-primary">Giá đại lý</h2>
                    <p className="mt-2 text-sm">
                        Tier {order.effective_tier.name} · {order.effective_tier.source}
                    </p>
                    <p className="text-sm">Người đặt: {order.placed_by?.name ?? "—"}</p>
                </section>
                <section className="rounded-xl border bg-card p-5">
                    <h2 className="dealer-section-title text-primary">Giao hàng</h2>
                    <p className="mt-2">
                        {order.recipient_name} · {order.recipient_phone}
                    </p>
                    <p className="text-sm">
                        {[
                            order.shipping_address_line1,
                            order.shipping_address_line2,
                            order.shipping_ward,
                            order.shipping_district,
                            order.shipping_city,
                            order.shipping_city === order.shipping_province
                                ? null
                                : order.shipping_province,
                            order.shipping_country,
                        ]
                            .filter(Boolean)
                            .join(", ")}
                    </p>
                    <p className="text-sm">Kho: {order.warehouse?.name ?? "—"}</p>
                    {order.delivery_note && (
                        <p className="text-sm">Ghi chú: {order.delivery_note}</p>
                    )}
                </section>
            </div>
            <section className="rounded-xl border bg-card p-5">
                <h2 className="dealer-section-title text-primary">Mặt hàng</h2>
                <div className="mt-3 divide-y">
                    {order.items?.map((item) => (
                        <div
                            key={item.id}
                            className="grid gap-2 py-4 text-[14px] md:grid-cols-[1fr_auto_auto]"
                        >
                            <div>
                                <strong>{item.sku}</strong> · {item.product_name} ·{" "}
                                {item.variant_name}
                                {item.is_gift && (
                                    <span className="dealer-meta ml-2 rounded-full bg-amber-100 px-2.5 py-1 text-amber-900">
                                        Quà tặng
                                    </span>
                                )}
                                <p>
                                    {formatProductQuantity(item.quantity)} {item.unit_name}{" "}
                                    {item.is_gift
                                        ? "· Quà tặng"
                                        : `· MOQ ${formatProductQuantity(item.minimum_quantity)}`}
                                </p>
                                {item.shipped_quantity !== null &&
                                    item.shipped_quantity !== undefined && (
                                        <p className="mt-1 text-sm font-medium text-primary">
                                            Đã xuất: {formatProductQuantity(item.shipped_quantity)}/
                                            {formatProductQuantity(item.quantity)} {item.unit_name}
                                        </p>
                                    )}
                            </div>
                            <span>{money(item.unit_price)}</span>
                            <strong>{money(item.line_total)}</strong>
                        </div>
                    ))}
                </div>
                {(order.promotions?.length || order.gift_promotion) && (
                    <div className="mt-4 space-y-1 text-right text-sm">
                        <p>Tạm tính: {money(order.subtotal)}</p>
                        {order.promotions?.map((promotion) => (
                            <p key={promotion.code}>
                                Ưu đãi {promotion.name} ({promotion.code}): −
                                {money(promotion.discount_amount)}
                            </p>
                        ))}
                        {order.gift_promotion && <p>Quà tặng: {order.gift_promotion.name}</p>}
                    </div>
                )}
                <p className="mt-4 text-right text-lg font-semibold">
                    Tổng: {money(order.grand_total)}
                </p>
                <div className="mt-3 grid gap-1 text-right text-sm">
                    <span>Đã thanh toán: {money(order.paid_amount)}</span>
                    <span>
                        Đã hoàn: {money(order.refunded_amount)} (
                        {salesOrderStatusLabel("refund", order.refund_status)})
                    </span>
                    <span>Thực thu sau hoàn: {money(order.net_settled_amount)}</span>
                    {order.refunds.map((refund) => (
                        <span key={refund.refund_code}>
                            {refund.refund_code}: {money(refund.amount)}
                        </span>
                    ))}
                    <strong>Còn phải thanh toán: {money(order.outstanding_amount)}</strong>
                </div>
            </section>
        </div>
    );
}

function DealerOrderRecord({ orderId }: { orderId: number }) {
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const [cancelOpen, setCancelOpen] = useState(false);
    const cancellationKey = useRef<string | null>(null);
    const accounts = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled: user?.role === "customer",
    });
    const accountId = accounts.data?.data[0]?.id ?? 0;
    const query = useQuery({
        queryKey: dealerKeys.order(user?.id, accountId, orderId),
        queryFn: () => dealerApi.order(accountId, orderId),
        enabled: user?.role === "customer" && accountId > 0 && orderId > 0,
        retry: false,
    });
    const cancellation = useMutation({
        mutationFn: () => {
            cancellationKey.current ??= crypto.randomUUID();
            return dealerApi.cancelOrder(accountId, orderId, cancellationKey.current);
        },
        onSuccess: (result) => {
            queryClient.setQueryData(dealerKeys.order(user?.id, accountId, orderId), result);
            void Promise.all([
                queryClient.invalidateQueries({ queryKey: ["dealer-orders", user?.id, accountId] }),
                queryClient.invalidateQueries({ queryKey: dealerKeys.wallet(user?.id, accountId) }),
                queryClient.invalidateQueries({
                    queryKey: ["dealer-wallet-transactions", user?.id, accountId],
                }),
                queryClient.invalidateQueries({ queryKey: dealerKeys.tier(user?.id, accountId) }),
                queryClient.invalidateQueries({
                    queryKey: dealerKeys.autoTier(user?.id, accountId),
                }),
            ]);
            cancellationKey.current = null;
            setCancelOpen(false);
        },
    });
    const order = query.data?.data;
    const canCancel =
        order?.order_status === "confirmed" &&
        order.fulfillment_status === "reserved" &&
        (Number(order.refundable_amount) === 0 || order.payment_method === "dealer_wallet");
    return (
        <Access>
            <main className="dealer-page-form space-y-6">
                <Link to="/dealer/orders" className="dealer-action text-primary underline">
                    ← Đơn hàng đại lý
                </Link>
                {accounts.isPending ? (
                    <LoadingState />
                ) : accounts.isError ? (
                    <ErrorState
                        message={errorMessage(accounts.error)}
                        retry={() => void accounts.refetch()}
                    />
                ) : accountId <= 0 || orderId <= 0 ? (
                    <ErrorState message="Không có tài khoản đại lý hoặc mã đơn hàng hợp lệ." />
                ) : query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState
                        message={errorMessage(query.error)}
                        retry={() => void query.refetch()}
                    />
                ) : order ? (
                    <>
                        <header>
                            <p className="label-luxury">Chi tiết đơn đại lý</p>
                            <h1 className="dealer-page-title mt-2 text-primary">
                                {order.order_code}
                            </h1>
                            {order.external_reference && (
                                <p className="mt-1 text-sm">
                                    Mã tham chiếu: {order.external_reference}
                                </p>
                            )}
                            <p className="dealer-meta mt-1 text-muted-foreground">
                                Nguồn: {salesOrderSourceLabel(order.order_source)}
                            </p>
                            <div
                                className="mt-3 flex flex-wrap gap-2"
                                aria-label="Trạng thái đơn hàng"
                            >
                                <SalesOrderStatusBadge
                                    kind="order"
                                    status={order.order_status}
                                    labeled
                                />
                                <SalesOrderStatusBadge
                                    kind="payment"
                                    status={order.payment_status}
                                    labeled
                                />
                                <SalesOrderStatusBadge
                                    kind="fulfillment"
                                    status={order.fulfillment_status}
                                    labeled
                                />
                                {order.refund_status !== "none" && (
                                    <SalesOrderStatusBadge
                                        kind="refund"
                                        status={order.refund_status}
                                        labeled
                                    />
                                )}
                            </div>
                        </header>
                        <OrderBody order={order} />
                        <CustomerReturnSection
                            channel={{ kind: "dealer", accountId }}
                            orderId={order.id}
                        />
                        {(order.items ?? []).some(
                            (item) => !item.is_gift && item.product_variant_id !== null,
                        ) && (
                            <Link
                                to="/dealer/quick-order"
                                search={{ sku: "", reorder: order.id }}
                                className="dealer-action rounded-md border px-4 py-2 text-primary hover:bg-accent"
                            >
                                Đặt lại
                            </Link>
                        )}
                        {canCancel && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => {
                                        cancellation.reset();
                                        setCancelOpen(true);
                                    }}
                                    className="dealer-action rounded-md border border-red-300 px-4 py-2 text-red-700 hover:bg-red-50"
                                >
                                    Hủy đơn hàng
                                </button>
                                <AlertDialog
                                    open={cancelOpen}
                                    onOpenChange={(open) => {
                                        if (!cancellation.isPending) setCancelOpen(open);
                                    }}
                                >
                                    <AlertDialogContent className="w-[calc(100%-2rem)] max-w-lg p-5 sm:p-6">
                                        <AlertDialogHeader>
                                            <AlertDialogTitle className="dealer-modal-title">
                                                Hủy đơn hàng {order.order_code}?
                                            </AlertDialogTitle>
                                            <AlertDialogDescription>
                                                {Number(order.refundable_amount) > 0
                                                    ? `Đơn hàng đã thanh toán ${money(order.paid_amount)} bằng ví.`
                                                    : Number(order.paid_amount) > 0
                                                      ? "Khoản thanh toán của đơn đã được hoàn trước đó."
                                                      : "Đơn hàng chưa có khoản thanh toán cần hoàn."}
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <ul className="list-disc space-y-2 pl-5 text-sm text-muted-foreground">
                                            {Number(order.refundable_amount) > 0 && (
                                                <li>
                                                    {money(order.refundable_amount)} sẽ được hoàn
                                                    lại vào ví.
                                                </li>
                                            )}
                                            <li>Sản phẩm đang giữ sẽ được trả lại tồn khả dụng.</li>
                                            <li>Đơn hàng sẽ chuyển sang Đã hủy.</li>
                                        </ul>
                                        {cancellation.isError && (
                                            <p role="alert" className="text-sm text-red-700">
                                                {cancellationError(cancellation.error)}
                                            </p>
                                        )}
                                        <AlertDialogFooter>
                                            <AlertDialogCancel disabled={cancellation.isPending}>
                                                Lúc khác
                                            </AlertDialogCancel>
                                            <button
                                                type="button"
                                                onClick={() => cancellation.mutate()}
                                                disabled={cancellation.isPending}
                                                className="dealer-action rounded-md bg-red-700 px-4 py-2 text-white hover:bg-red-800 disabled:opacity-50"
                                            >
                                                {cancellation.isPending
                                                    ? "Đang hủy..."
                                                    : "Xác nhận hủy"}
                                            </button>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </>
                        )}
                    </>
                ) : (
                    <ErrorState message="Không tìm thấy đơn hàng đại lý." />
                )}
            </main>
        </Access>
    );
}

export function DealerOrderDetailPage({ orderId }: { orderId: number }) {
    return <DealerOrderRecord orderId={orderId} />;
}
