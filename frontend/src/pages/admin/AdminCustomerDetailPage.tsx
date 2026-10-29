import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { Badge, StatusBadge } from "@/components/common/Status";
import { LoyaltyCard } from "@/components/loyalty/LoyaltyCard";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { formatProductQuantity } from "@/lib/productQuantity";
import { adminApi } from "@/services/adminApi";
import { errorMessage } from "@/services/api";
import type { AdminCustomerPurchasedProduct } from "@/types";
import { AdminGuard, AdminTitle } from "./AdminPages";

const currency = (value: number | string) =>
    `${new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 0 }).format(Number(value))} ₫`;
const date = (value: string | null | undefined) =>
    value
        ? new Intl.DateTimeFormat("vi-VN", {
              day: "2-digit",
              month: "2-digit",
              year: "numeric",
          }).format(new Date(value))
        : "—";
const dateTime = (value: string) =>
    new Intl.DateTimeFormat("vi-VN", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    }).format(new Date(value));

const orderLabels: Record<string, string> = {
    pending: "Chờ xác nhận",
    confirmed: "Đã xác nhận",
    preparing: "Đang chuẩn bị",
    processing: "Đang xử lý",
    shipping: "Đang giao",
    delivered: "Đã giao",
    completed: "Hoàn thành",
    cancelled: "Đã hủy",
};
const paymentLabels: Record<string, string> = {
    unpaid: "Chưa thanh toán",
    partially_paid: "Thanh toán một phần",
    paid: "Đã thanh toán",
};

function SectionHeader({ title, children }: { title: string; children?: React.ReactNode }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-xl font-semibold text-primary">{title}</h2>
            {children}
        </div>
    );
}

function ProductTable({ products }: { products: AdminCustomerPurchasedProduct[] }) {
    if (products.length === 0) return <EmptyState message="Khách hàng chưa mua sản phẩm nào." />;

    return (
        <div className="mt-4 max-w-full overflow-x-auto">
            <table className="w-full min-w-[650px] text-sm">
                <thead>
                    <tr className="border-b text-left text-muted-foreground">
                        <th className="p-3">Sản phẩm</th>
                        <th className="p-3">SKU</th>
                        <th className="p-3 text-right">Tổng SL đã mua</th>
                        <th className="p-3">Lần mua gần nhất</th>
                    </tr>
                </thead>
                <tbody>
                    {products.map((product) => (
                        <tr
                            key={`${product.product_variant_id}-${product.sku}`}
                            className="border-b last:border-0"
                        >
                            <td className="p-3">
                                {product.product_id ? (
                                    <Link
                                        to="/admin/products/$id"
                                        params={{ id: String(product.product_id) }}
                                        className="font-semibold text-primary underline underline-offset-2"
                                    >
                                        {product.product_name}
                                    </Link>
                                ) : (
                                    <span className="font-semibold">{product.product_name}</span>
                                )}
                                {product.variant_name && (
                                    <p className="text-xs text-muted-foreground">
                                        {product.variant_name}
                                    </p>
                                )}
                            </td>
                            <td className="p-3 font-mono text-xs">{product.sku}</td>
                            <td className="p-3 text-right font-semibold">
                                {formatProductQuantity(product.total_quantity)}
                            </td>
                            <td className="p-3 whitespace-nowrap">
                                {date(product.last_purchased_at)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function AdminCustomerDetailPage({ id }: { id: number }) {
    const [allProductsOpen, setAllProductsOpen] = useState(false);
    const [productPage, setProductPage] = useState(1);
    const [allVouchersOpen, setAllVouchersOpen] = useState(false);
    const [voucherPage, setVoucherPage] = useState(1);
    const customer = useQuery({
        queryKey: ["admin-customer", id],
        queryFn: () => adminApi.customer(id),
        retry: false,
    });
    const purchases = useQuery({
        queryKey: ["admin-customer-purchases", id],
        queryFn: () => adminApi.customerPurchases(id),
        enabled: customer.isSuccess,
    });
    const products = useQuery({
        queryKey: ["admin-customer-products", id, productPage],
        queryFn: () => adminApi.customerPurchasedProducts(id, productPage),
        enabled: allProductsOpen,
    });
    const vouchers = useQuery({
        queryKey: ["admin-customer-vouchers", id, voucherPage],
        queryFn: () => adminApi.customerVouchers(id, voucherPage),
        enabled: allVouchersOpen,
    });

    return (
        <AdminGuard>
            <Link
                to="/admin/customers"
                className="mb-5 inline-block text-sm font-semibold text-primary hover:underline"
            >
                ← Quay lại danh sách khách hàng
            </Link>
            <AdminTitle
                title="Chi tiết khách hàng"
                description="Thông tin mua hàng Retail, voucher và hoạt động đặt lịch của khách hàng."
            />
            {customer.isPending ? (
                <LoadingState />
            ) : customer.isError ? (
                <ErrorState
                    message={errorMessage(customer.error)}
                    retry={() => customer.refetch()}
                />
            ) : (
                <div className="mt-7 grid gap-6">
                    <section className="card-surface p-5 sm:p-6">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="mr-2 text-2xl font-semibold text-primary">
                                {customer.data.data.name}
                            </h2>
                            <Badge tone="info">Khách hàng Retail</Badge>
                            <Badge
                                tone={
                                    customer.data.data.status === "active" ? "success" : "default"
                                }
                            >
                                {customer.data.data.status === "active"
                                    ? "Đang hoạt động"
                                    : customer.data.data.status === "inactive"
                                      ? "Ngừng hoạt động"
                                      : "Đã gộp hồ sơ"}
                            </Badge>
                        </div>
                        <div className="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <p className="text-muted-foreground">Email</p>
                                <p className="mt-1 break-all font-medium">
                                    {customer.data.data.email || "—"}
                                </p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">Số điện thoại</p>
                                <p className="mt-1 font-medium">
                                    {customer.data.data.phone || "—"}
                                </p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">Ngày tham gia</p>
                                <p className="mt-1 font-medium">
                                    {date(customer.data.data.created_at)}
                                </p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">Đơn hàng gần nhất</p>
                                <p className="mt-1 font-medium">
                                    {purchases.data?.data.last_order_at
                                        ? date(purchases.data.data.last_order_at)
                                        : purchases.isPending
                                          ? "Đang tải..."
                                          : purchases.isError
                                            ? "Không tải được"
                                            : "Chưa có đơn hàng"}
                                </p>
                            </div>
                        </div>
                    </section>

                    <section className="min-w-0">
                        <SectionHeader title="Tổng quan mua hàng" />
                        {purchases.isPending ? (
                            <LoadingState />
                        ) : purchases.isError ? (
                            <ErrorState
                                message={errorMessage(purchases.error)}
                                retry={() => purchases.refetch()}
                            />
                        ) : (
                            <div className="mt-4 grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                                {[
                                    ["Tổng đơn hàng", purchases.data.data.statistics.total_orders],
                                    [
                                        "Đơn hoàn thành",
                                        purchases.data.data.statistics.completed_orders,
                                    ],
                                    [
                                        "Đang xử lý",
                                        purchases.data.data.statistics.processing_orders,
                                    ],
                                    ["Đã hủy", purchases.data.data.statistics.cancelled_orders],
                                    [
                                        "Tổng chi tiêu",
                                        currency(purchases.data.data.statistics.total_spent),
                                    ],
                                    [
                                        "Trung bình / đơn",
                                        currency(
                                            purchases.data.data.statistics.average_order_value,
                                        ),
                                    ],
                                ].map(([label, value]) => (
                                    <div key={label} className="card-surface min-w-0 p-4">
                                        <p className="text-xs text-muted-foreground">{label}</p>
                                        <p className="mt-2 whitespace-nowrap text-lg font-semibold text-primary sm:text-xl">
                                            {value}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>

                    <section className="card-surface min-w-0 p-5 sm:p-6">
                        <SectionHeader title="Đơn hàng gần đây">
                            {customer.data.data.user_id && (
                                <Link
                                    to="/admin/sales-orders/retail"
                                    search={{ buyer: customer.data.data.user_id }}
                                    className="text-sm font-semibold text-primary underline underline-offset-2"
                                >
                                    Xem tất cả đơn hàng
                                </Link>
                            )}
                        </SectionHeader>
                        {purchases.isPending ? (
                            <LoadingState />
                        ) : purchases.isError ? (
                            <ErrorState
                                message={errorMessage(purchases.error)}
                                retry={() => purchases.refetch()}
                            />
                        ) : purchases.data.data.recent_orders.length === 0 ? (
                            <EmptyState message="Khách hàng chưa có đơn hàng." />
                        ) : (
                            <div className="mt-4 max-w-full overflow-x-auto">
                                <table className="w-full min-w-[790px] text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="p-3">Mã đơn</th>
                                            <th className="p-3">Ngày đặt</th>
                                            <th className="p-3 text-right">Số SP</th>
                                            <th className="p-3 text-right">Tổng tiền</th>
                                            <th className="p-3">Thanh toán</th>
                                            <th className="p-3">Trạng thái</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {purchases.data.data.recent_orders.map((order) => (
                                            <tr key={order.id} className="border-b last:border-0">
                                                <td className="p-3">
                                                    <Link
                                                        to="/admin/sales-orders/$id"
                                                        params={{ id: String(order.id) }}
                                                        className="font-semibold text-primary underline underline-offset-2"
                                                    >
                                                        {order.order_code}
                                                    </Link>
                                                </td>
                                                <td className="p-3 whitespace-nowrap">
                                                    {dateTime(order.created_at)}
                                                </td>
                                                <td className="p-3 text-right">
                                                    {formatProductQuantity(order.item_quantity)}
                                                </td>
                                                <td className="p-3 whitespace-nowrap text-right font-semibold">
                                                    {currency(order.grand_total)}
                                                </td>
                                                <td className="p-3">
                                                    <Badge
                                                        tone={
                                                            order.refund_status !== "none"
                                                                ? "warning"
                                                                : order.payment_status === "paid"
                                                                  ? "success"
                                                                  : "default"
                                                        }
                                                    >
                                                        {order.refund_status === "fully_refunded"
                                                            ? "Đã hoàn tiền"
                                                            : order.refund_status ===
                                                                "partially_refunded"
                                                              ? "Hoàn tiền một phần"
                                                              : paymentLabels[
                                                                    order.payment_status
                                                                ] || order.payment_status}
                                                    </Badge>
                                                </td>
                                                <td className="p-3">
                                                    <Badge
                                                        tone={
                                                            order.order_status === "completed"
                                                                ? "success"
                                                                : order.order_status === "cancelled"
                                                                  ? "danger"
                                                                  : "info"
                                                        }
                                                    >
                                                        {orderLabels[order.order_status] ||
                                                            order.order_status}
                                                    </Badge>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section className="card-surface min-w-0 p-5 sm:p-6">
                        <SectionHeader title="Sản phẩm đã mua">
                            {(purchases.data?.data.purchased_products_total ?? 0) > 0 && (
                                <button
                                    type="button"
                                    onClick={() => setAllProductsOpen(true)}
                                    className="text-sm font-semibold text-primary underline underline-offset-2"
                                >
                                    Xem tất cả sản phẩm đã mua
                                </button>
                            )}
                        </SectionHeader>
                        {purchases.isPending ? (
                            <LoadingState />
                        ) : purchases.isError ? (
                            <ErrorState
                                message={errorMessage(purchases.error)}
                                retry={() => purchases.refetch()}
                            />
                        ) : (
                            <ProductTable products={purchases.data.data.purchased_products} />
                        )}
                    </section>

                    <section className="card-surface p-5 sm:p-6">
                        <SectionHeader title="Voucher của khách hàng" />
                        <p className="mt-2 text-sm text-muted-foreground">
                            Voucher khả dụng: {customer.data.data.statistics.available_vouchers}
                        </p>
                        {customer.data.data.available_vouchers.length === 0 ? (
                            <EmptyState message="Khách hàng chưa có voucher khả dụng." />
                        ) : (
                            <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {customer.data.data.available_vouchers.map((voucher) => (
                                    <article key={voucher.id} className="rounded-lg border p-4">
                                        <p className="font-semibold text-primary">{voucher.code}</p>
                                        <p className="mt-1 text-sm">
                                            Giảm {Number(voucher.value)}%
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Hết hạn: {date(voucher.expires_at)}
                                        </p>
                                    </article>
                                ))}
                            </div>
                        )}
                        {customer.data.data.statistics.available_vouchers > 8 && (
                            <button
                                type="button"
                                onClick={() => setAllVouchersOpen(true)}
                                className="mt-4 text-sm font-semibold text-primary underline"
                            >
                                Xem tất cả voucher
                            </button>
                        )}
                    </section>

                    <section className="card-surface min-w-0 p-5 sm:p-6">
                        <SectionHeader title="Hoạt động đặt lịch">
                            {customer.data.data.user_id && (
                                <Link
                                    to="/admin/appointments"
                                    search={{ customer: customer.data.data.user_id }}
                                    className="text-sm font-semibold text-primary underline underline-offset-2"
                                >
                                    Xem tất cả lịch hẹn
                                </Link>
                            )}
                        </SectionHeader>
                        <div className="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-5">
                            {[
                                ["Tổng lịch", customer.data.data.statistics.total_appointments],
                                [
                                    "Hoàn thành",
                                    customer.data.data.statistics.completed_appointments,
                                ],
                                ["Sắp tới", customer.data.data.statistics.upcoming_appointments],
                                ["Đã hủy", customer.data.data.statistics.cancelled_appointments],
                                ["Đánh giá", customer.data.data.statistics.reviews],
                            ].map(([label, value]) => (
                                <div key={label} className="rounded-lg border p-3">
                                    <p className="text-xs text-muted-foreground">{label}</p>
                                    <p className="mt-1 text-lg font-semibold text-primary">
                                        {value}
                                    </p>
                                </div>
                            ))}
                        </div>
                        <h3 className="mt-6 font-semibold text-primary">Lịch hẹn gần đây</h3>
                        {customer.data.data.recent_appointments.length === 0 ? (
                            <EmptyState message="Khách hàng chưa có lịch hẹn." />
                        ) : (
                            <div className="mt-3 max-w-full overflow-x-auto">
                                <table className="w-full min-w-[650px] text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="p-3">Mã lịch</th>
                                            <th className="p-3">Dịch vụ</th>
                                            <th className="p-3">Bác sĩ</th>
                                            <th className="p-3">Thời gian</th>
                                            <th className="p-3">Trạng thái</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {customer.data.data.recent_appointments.map(
                                            (appointment) => (
                                                <tr
                                                    key={appointment.id}
                                                    className="border-b last:border-0"
                                                >
                                                    <td className="p-3">
                                                        <Link
                                                            to="/admin/appointments/$id"
                                                            params={{ id: String(appointment.id) }}
                                                            className="font-semibold text-primary underline underline-offset-2"
                                                        >
                                                            {appointment.booking_code}
                                                        </Link>
                                                    </td>
                                                    <td className="p-3">
                                                        {appointment.service_name || "—"}
                                                    </td>
                                                    <td className="p-3">
                                                        {appointment.doctor_name || "—"}
                                                    </td>
                                                    <td className="p-3 whitespace-nowrap">
                                                        {date(appointment.appointment_date)}{" "}
                                                        {appointment.start_time?.slice(0, 5)}
                                                    </td>
                                                    <td className="p-3">
                                                        <StatusBadge status={appointment.status} />
                                                    </td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section className="grid gap-4">
                        <SectionHeader title="Quyền lợi thành viên" />
                        <LoyaltyCard summary={customer.data.data.loyalty} />
                        {customer.data.data.loyalty_vouchers.length > 0 && (
                            <div className="card-surface p-5">
                                <h3 className="font-semibold text-primary">
                                    Voucher thành viên đã cấp
                                </h3>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {customer.data.data.loyalty_vouchers.map((voucher) => (
                                        <Badge
                                            key={voucher.id}
                                            tone={
                                                voucher.status === "active" ? "success" : "default"
                                            }
                                        >
                                            {voucher.code} · {Number(voucher.value)}% ·{" "}
                                            {date(voucher.expires_at)}
                                        </Badge>
                                    ))}
                                </div>
                            </div>
                        )}
                    </section>
                </div>
            )}
            <Dialog open={allProductsOpen} onOpenChange={setAllProductsOpen}>
                <DialogContent className="max-h-[85vh] max-w-4xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Tất cả sản phẩm đã mua</DialogTitle>
                        <DialogDescription>
                            Tổng hợp sản phẩm Retail theo SKU và biến thể.
                        </DialogDescription>
                    </DialogHeader>
                    {products.isPending ? (
                        <LoadingState />
                    ) : products.isError ? (
                        <ErrorState
                            message={errorMessage(products.error)}
                            retry={() => products.refetch()}
                        />
                    ) : (
                        <>
                            <ProductTable products={products.data.data} />
                            <Pagination
                                current={products.data.meta.current_page}
                                last={products.data.meta.last_page}
                                onPage={setProductPage}
                            />
                        </>
                    )}
                </DialogContent>
            </Dialog>
            <Dialog open={allVouchersOpen} onOpenChange={setAllVouchersOpen}>
                <DialogContent className="max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Voucher của khách hàng</DialogTitle>
                        <DialogDescription>Danh sách voucher khả dụng.</DialogDescription>
                    </DialogHeader>
                    {vouchers.isPending ? (
                        <LoadingState />
                    ) : vouchers.isError ? (
                        <ErrorState
                            message={errorMessage(vouchers.error)}
                            retry={() => vouchers.refetch()}
                        />
                    ) : (
                        <>
                            {vouchers.data.data.map((voucher) => (
                                <div key={voucher.id} className="rounded-lg border p-3 text-sm">
                                    <strong>{voucher.code}</strong> · Giảm {Number(voucher.value)}%
                                    · Hết hạn {date(voucher.expires_at)}
                                </div>
                            ))}
                            <Pagination
                                current={vouchers.data.meta.current_page}
                                last={vouchers.data.meta.last_page}
                                onPage={setVoucherPage}
                            />
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </AdminGuard>
    );
}
