import { SalesOrderStatusBadge } from "@/components/common/SalesOrderStatusBadge";
import { useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate } from "@tanstack/react-router";
import { toast } from "sonner";
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from "@/components/ui/sheet";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import {
    salesOrderSourceLabel,
    salesOrderStatusLabel,
    salesOrderTimelineLabel,
} from "@/components/common/salesOrderStatus";
import { ApiError, errorMessage } from "@/services/api";
import { formatProductQuantity, isPositiveProductQuantity } from "@/lib/productQuantity";
import { inventoryApi } from "@/services/inventoryApi";
import { salesOrderApi, salesOrderKeys, type SalesOrderFilters } from "@/services/salesOrderApi";
import { ReturnRefundSection } from "./ReturnRefundSection";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "./ProductAdminShared";

const money = (value: string) => `${new Intl.NumberFormat("vi-VN").format(Number(value))} ₫`;
const paymentMethodText: Record<string, string> = {
    cod: "COD",
    bank_transfer: "Chuyển khoản",
    dealer_wallet: "Ví đại lý",
    cash: "Tiền mặt",
    other_manual: "Thanh toán khác",
};

export function SalesOrderListPage({
    channel,
    buyerUserId,
}: {
    channel: "all" | "dealer" | "retail";
    buyerUserId?: number;
}) {
    const navigate = useNavigate();
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("");
    const [source, setSource] = useState<"" | "admin" | "cart" | "quick_order" | "dealer_excel">(
        "",
    );
    const [payment, setPayment] = useState("");
    const [warehouseId, setWarehouseId] = useState("");
    const [dateFrom, setDateFrom] = useState("");
    const [dateTo, setDateTo] = useState("");
    const [page, setPage] = useState(1);
    const filters: SalesOrderFilters = {
        search,
        ...(buyerUserId ? { buyer_user_id: buyerUserId } : {}),
        order_status: status,
        sales_channel: channel === "all" ? "" : channel,
        order_source: source,
        payment_status: payment,
        ...(warehouseId ? { warehouse_id: Number(warehouseId) } : {}),
        date_from: dateFrom,
        date_to: dateTo,
        page,
    };
    const warehouses = useQuery({
        queryKey: ["sales-order-warehouses"],
        queryFn: () => inventoryApi.warehouses({ status: "active", per_page: 100 }),
    });
    const query = useQuery({
        queryKey: salesOrderKeys.list(filters),
        queryFn: () => salesOrderApi.list(filters),
    });
    const pendingReturns = useQuery({
        queryKey: ["admin-pending-returns"],
        queryFn: salesOrderApi.pendingReturns,
        enabled: !buyerUserId,
    });
    const visiblePendingReturns = (pendingReturns.data?.data ?? []).filter(
        (entry) => channel === "all" || entry.sales_channel === channel,
    );
    const title =
        channel === "all" ? "Tất cả đơn" : channel === "dealer" ? "Đơn đại lý" : "Đơn bán lẻ";
    const description =
        channel === "all"
            ? "Theo dõi đơn bán lẻ và đại lý trong cùng quy trình giữ hàng, xuất hàng và hủy đơn."
            : channel === "dealer"
              ? "Theo dõi các đơn thuộc kênh đại lý."
              : "Theo dõi các đơn thuộc kênh bán lẻ.";

    return (
        <ProductAdminGuard>
            <div className="space-y-6">
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="label-luxury">Sales Order Core</p>
                        <h1 className="mt-2 text-3xl text-primary">{title}</h1>
                        <p className="mt-2 text-sm text-muted-foreground">{description}</p>
                    </div>
                    {channel !== "dealer" && (
                        <Link to="/admin/sales-orders/new" className={buttonClass}>
                            + Tạo đơn Retail
                        </Link>
                    )}
                </header>
                {visiblePendingReturns.length > 0 && (
                    <section className="rounded-xl border border-amber-200 bg-amber-50/60 p-4">
                        <h2 className="font-semibold text-primary">
                            Yêu cầu trả hàng chờ duyệt ({visiblePendingReturns.length})
                        </h2>
                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                            {visiblePendingReturns.slice(0, 6).map((entry) => (
                                <Link
                                    key={entry.id}
                                    to="/admin/sales-orders/$id"
                                    params={{ id: String(entry.sales_order_id) }}
                                    className="rounded-lg border bg-card p-3 text-sm hover:border-primary"
                                >
                                    <strong>{entry.return_code}</strong> · {entry.order_code}
                                    <br />
                                    {entry.requested_by ?? entry.recipient_name} ·{" "}
                                    {entry.sales_channel === "dealer" ? "Đại lý" : "Retail"}
                                </Link>
                            ))}
                        </div>
                    </section>
                )}
                {channel === "retail" && buyerUserId && (
                    <div className="flex flex-wrap items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm">
                        <span>Đang xem đơn Retail của khách hàng #{buyerUserId}.</span>
                        <Link
                            to="/admin/sales-orders/retail"
                            search={{}}
                            className="font-semibold text-primary underline underline-offset-2"
                        >
                            Xóa bộ lọc khách hàng
                        </Link>
                    </div>
                )}
                <section className="rounded-xl border bg-card p-5">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <input
                            className={fieldClass}
                            aria-label="Tìm đơn hàng"
                            placeholder="Mã đơn hoặc người nhận"
                            value={search}
                            onChange={(event) => {
                                setSearch(event.target.value);
                                setPage(1);
                            }}
                        />
                        <select
                            className={fieldClass}
                            aria-label="Trạng thái đơn"
                            value={status}
                            onChange={(event) => {
                                setStatus(event.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">Tất cả trạng thái</option>
                            {[
                                "draft",
                                "pending",
                                "confirmed",
                                "preparing",
                                "shipping",
                                "delivered",
                                "processing",
                                "completed",
                                "cancelled",
                            ].map((value) => (
                                <option key={value} value={value}>
                                    {salesOrderStatusLabel("order", value)}
                                </option>
                            ))}
                        </select>
                        <select
                            className={fieldClass}
                            aria-label="Nguồn đơn"
                            value={source}
                            onChange={(event) => {
                                setSource(event.target.value as typeof source);
                                setPage(1);
                            }}
                        >
                            <option value="">Tất cả nguồn</option>
                            {channel !== "dealer" && <option value="admin">Admin</option>}
                            {channel !== "dealer" && <option value="cart">Retail Cart</option>}
                            {channel !== "retail" && (
                                <option value="quick_order">Quick Order</option>
                            )}
                            {channel !== "retail" && (
                                <option value="dealer_excel">Dealer Excel</option>
                            )}
                        </select>
                    </div>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <select
                            className={fieldClass}
                            aria-label="Trạng thái thanh toán"
                            value={payment}
                            onChange={(event) => {
                                setPayment(event.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">Mọi thanh toán</option>
                            <option value="unpaid">Chưa thanh toán</option>
                            <option value="pending">Chờ thanh toán</option>
                            <option value="partially_paid">Thanh toán một phần</option>
                            <option value="paid">Đã thanh toán</option>
                            <option value="partially_refunded">Hoàn tiền một phần</option>
                            <option value="refunded">Đã hoàn tiền</option>
                        </select>
                        <select
                            className={fieldClass}
                            aria-label="Kho"
                            value={warehouseId}
                            onChange={(event) => {
                                setWarehouseId(event.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">Mọi kho</option>
                            {warehouses.data?.data.map((warehouse) => (
                                <option key={warehouse.id} value={warehouse.id}>
                                    {warehouse.code} · {warehouse.name}
                                </option>
                            ))}
                        </select>
                        <input
                            type="date"
                            className={fieldClass}
                            aria-label="Từ ngày"
                            value={dateFrom}
                            onChange={(event) => {
                                setDateFrom(event.target.value);
                                setPage(1);
                            }}
                        />
                        <input
                            type="date"
                            className={fieldClass}
                            aria-label="Đến ngày"
                            min={dateFrom}
                            value={dateTo}
                            onChange={(event) => {
                                setDateTo(event.target.value);
                                setPage(1);
                            }}
                        />
                    </div>
                    {query.isPending ? (
                        <LoadingState />
                    ) : query.isError ? (
                        <ErrorState
                            message={errorMessage(query.error)}
                            retry={() => query.refetch()}
                        />
                    ) : query.data.data.length === 0 ? (
                        <div className="mt-5">
                            <EmptyState message="Chưa có đơn phù hợp." />
                        </div>
                    ) : (
                        <>
                            <div className="mt-5 overflow-x-auto">
                                <table className="w-full min-w-[1080px] text-left text-sm">
                                    <thead className="border-b text-muted-foreground">
                                        <tr>
                                            <th className="py-2">Đơn</th>
                                            <th>Người mua / nhận</th>
                                            <th>Kênh / Đại lý</th>
                                            <th>Kho</th>
                                            <th>Mặt hàng</th>
                                            <th>Tổng</th>
                                            <th>Trạng thái</th>
                                            <th>Thanh toán</th>
                                            <th>Xuất hàng</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {query.data.data.map((order) => (
                                            <tr
                                                key={order.id}
                                                tabIndex={0}
                                                role="link"
                                                aria-label={`Xem chi tiết đơn ${order.order_code}`}
                                                className="cursor-pointer border-b transition-colors hover:bg-muted/40 focus-visible:bg-muted/50 focus-visible:outline-2 focus-visible:outline-primary last:border-0"
                                                onClick={() =>
                                                    navigate({
                                                        to: "/admin/sales-orders/$id",
                                                        params: { id: String(order.id) },
                                                    })
                                                }
                                                onKeyDown={(event) => {
                                                    if (
                                                        event.key === "Enter" ||
                                                        event.key === " "
                                                    ) {
                                                        event.preventDefault();
                                                        navigate({
                                                            to: "/admin/sales-orders/$id",
                                                            params: { id: String(order.id) },
                                                        });
                                                    }
                                                }}
                                            >
                                                <td className="py-3">
                                                    <span className="font-semibold text-primary">
                                                        {order.order_code}
                                                    </span>
                                                    <p className="text-xs text-muted-foreground">
                                                        {new Date(order.created_at).toLocaleString(
                                                            "vi-VN",
                                                        )}
                                                    </p>
                                                    {order.external_reference && (
                                                        <p
                                                            className="max-w-36 truncate text-xs text-muted-foreground"
                                                            title={order.external_reference}
                                                        >
                                                            #{order.external_reference}
                                                        </p>
                                                    )}
                                                </td>
                                                <td>
                                                    <strong className="font-medium">
                                                        {order.recipient_name}
                                                    </strong>
                                                    <p className="text-xs text-muted-foreground">
                                                        {order.recipient_phone}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        Tạo bởi: {order.buyer?.name}
                                                    </p>
                                                </td>
                                                <td>
                                                    {order.sales_channel === "dealer"
                                                        ? "Đại lý"
                                                        : "Retail"}
                                                    <p className="text-xs text-muted-foreground">
                                                        {salesOrderSourceLabel(order.order_source)}
                                                    </p>
                                                    {order.dealer_name_snapshot && (
                                                        <p>{order.dealer_name_snapshot}</p>
                                                    )}
                                                </td>
                                                <td>{order.warehouse?.code}</td>
                                                <td>
                                                    {order.items_count} SKU ·{" "}
                                                    {formatProductQuantity(
                                                        order.total_quantity ?? "0",
                                                    )}{" "}
                                                    SP
                                                </td>
                                                <td className="font-medium">
                                                    {money(order.grand_total)}
                                                </td>
                                                <td>
                                                    <SalesOrderStatusBadge
                                                        kind="order"
                                                        status={order.order_status}
                                                    />
                                                </td>
                                                <td className="py-2">
                                                    <SalesOrderStatusBadge
                                                        kind="payment"
                                                        status={order.payment_status}
                                                    />
                                                    {order.payment_method && (
                                                        <p className="mt-1 text-xs font-medium text-muted-foreground">
                                                            {paymentMethodText[
                                                                order.payment_method
                                                            ] ?? "Phương thức khác"}
                                                        </p>
                                                    )}
                                                </td>
                                                <td>
                                                    <SalesOrderStatusBadge
                                                        kind="fulfillment"
                                                        status={order.fulfillment_status}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <Pagination
                                current={query.data.current_page}
                                last={query.data.last_page}
                                onPage={setPage}
                            />
                        </>
                    )}
                </section>
            </div>
        </ProductAdminGuard>
    );
}

export { SalesOrderCreatePage } from "./RetailOrderCreatePage";

export function SalesOrderDetailPage({ id }: { id: number }) {
    const client = useQueryClient();
    const query = useQuery({
        queryKey: salesOrderKeys.detail(id),
        queryFn: () => salesOrderApi.detail(id),
    });
    const paymentsQuery = useQuery({
        queryKey: salesOrderKeys.payments(id),
        queryFn: () => salesOrderApi.payments(id),
    });
    const [paymentAmount, setPaymentAmount] = useState("");
    const [paymentMethod, setPaymentMethod] = useState<"cash" | "bank_transfer" | "other_manual">(
        "bank_transfer",
    );
    const [paymentReference, setPaymentReference] = useState("");
    const [paymentNote, setPaymentNote] = useState("");
    const [paymentOpen, setPaymentOpen] = useState(false);
    const [warehouseOpen, setWarehouseOpen] = useState(false);
    const [paymentHistoryOpen, setPaymentHistoryOpen] = useState(false);
    const [fulfillmentHistoryOpen, setFulfillmentHistoryOpen] = useState(false);
    const [orderHistoryOpen, setOrderHistoryOpen] = useState(false);
    const [fulfillmentOpen, setFulfillmentOpen] = useState(false);
    const [busy, setBusy] = useState("");
    const [notice, setNotice] = useState("");
    const [stalePrice, setStalePrice] = useState(false);
    const [quantities, setQuantities] = useState<Record<number, string>>({});
    const [selectedFulfillItems, setSelectedFulfillItems] = useState<Record<number, boolean>>({});
    const [dialog, setDialog] = useState<{
        title: string;
        description?: string;
        execute: (reason: string) => Promise<boolean>;
        needsReason?: boolean;
    } | null>(null);
    const [cancelReason, setCancelReason] = useState("");
    const [selectedWarehouse, setSelectedWarehouse] = useState("");
    const warehouses = useQuery({
        queryKey: ["sales-order-warehouses"],
        queryFn: () => inventoryApi.warehouses({ status: "active", per_page: 100 }),
    });
    const retryKeys = useRef<Record<string, string>>({});
    const actionPending = useRef(false);
    const action = async (
        name: string,
        payload: string,
        call: (key: string) => Promise<unknown>,
    ): Promise<boolean> => {
        if (actionPending.current) return false;
        actionPending.current = true;
        const signature = `${name}:${payload}`;
        retryKeys.current[signature] ??= globalThis.crypto.randomUUID();
        setBusy(name);
        setNotice("");
        try {
            await call(retryKeys.current[signature]!);
            delete retryKeys.current[signature];
            setStalePrice(false);
            const messages: Record<string, string> = {
                cancel: "Đã hủy đơn hàng.",
                reprice: "Đã cập nhật giá đơn hàng.",
                confirm: "Đã xác nhận đơn hàng.",
                fulfill: "Đã xác nhận xuất hàng.",
                preparing: "Đơn hàng đang được chuẩn bị.",
                shipping: "Đã xuất kho và chuyển giao hàng.",
                delivered: "Đã xác nhận giao hàng.",
                completed: "Đã hoàn thành đơn hàng.",
                warehouse: "Đã đổi kho xuất hàng.",
            };
            if (name !== "payment")
                toast.success(messages[name] ?? "Cập nhật đơn hàng thành công.");
            await client.invalidateQueries({ queryKey: salesOrderKeys.detail(id) });
            await client.invalidateQueries({ queryKey: salesOrderKeys.payments(id) });
            await client.invalidateQueries({ queryKey: ["sales-orders"] });
            return true;
        } catch (reason) {
            if (reason instanceof ApiError && reason.code === "ORDER_PRICE_CHANGED")
                setStalePrice(true);
            if (reason instanceof ApiError && reason.code === "INSUFFICIENT_STOCK") {
                setNotice(
                    `Thiếu tồn kho SKU ${reason.details.sku ?? ""}: cần ${formatProductQuantity(reason.details.requested)}, còn ${formatProductQuantity(reason.details.available)}.`,
                );
            } else {
                setNotice(errorMessage(reason));
            }
            toast.error(errorMessage(reason));
            return false;
        } finally {
            actionPending.current = false;
            setBusy("");
        }
    };

    if (query.isPending)
        return (
            <ProductAdminGuard>
                <LoadingState />
            </ProductAdminGuard>
        );
    if (query.isError)
        return (
            <ProductAdminGuard>
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            </ProductAdminGuard>
        );
    const order = query.data.data;
    const canRecordPayment =
        !["draft", "cancelled"].includes(order.order_status) &&
        Number(order.outstanding_amount) > 0;
    const paymentAmountValid =
        /^\d+(?:\.\d{1,2})?$/.test(paymentAmount) &&
        Number(paymentAmount) > 0 &&
        Number(paymentAmount) <= Number(order.outstanding_amount);
    const recordPayment = async () => {
        if (!paymentAmountValid) return;
        const body = {
            amount: paymentAmount,
            payment_method: paymentMethod,
            ...(paymentReference.trim() && { external_reference: paymentReference.trim() }),
            ...(paymentNote.trim() && { note: paymentNote.trim() }),
        };
        const succeeded = await action("payment", JSON.stringify(body), (key) =>
            salesOrderApi.recordPayment(id, { ...body, operation_key: key }),
        );
        if (succeeded) {
            setPaymentOpen(false);
            setPaymentAmount("");
            setPaymentReference("");
            setPaymentNote("");
            toast.success("Đã ghi nhận thanh toán.");
        }
    };
    const cancel = () =>
        setDialog({
            title: `Hủy đơn hàng ${order.order_code}?`,
            description: `${order.fulfillment_status === "reserved" ? "Hàng đang giữ sẽ được trả lại tồn kho. " : ""}${Number(order.paid_amount) > 0 ? "Đơn đã có khoản thanh toán; nếu cần hoàn tiền, hãy xử lý riêng theo quy trình hoàn tiền." : "Thao tác này sẽ cập nhật trạng thái đơn hàng."}`,
            needsReason: true,
            execute: (reason) =>
                action("cancel", reason, (key) => salesOrderApi.cancel(id, key, reason)),
        });
    const fulfillmentRows = order.items.map((item) => ({
        item,
        remaining: item.reservation
            ? Number(item.reservation.original_quantity) -
              Number(item.reservation.consumed_quantity) -
              Number(item.reservation.released_quantity)
            : 0,
    }));
    const fulfillItems = fulfillmentRows
        .filter(
            ({ item, remaining }) =>
                remaining > 0 &&
                selectedFulfillItems[item.id] === true &&
                isPositiveProductQuantity(quantities[item.id] ?? "") &&
                Number(quantities[item.id]) <= remaining,
        )
        .map(({ item }) => ({ item_id: item.id, quantity: quantities[item.id]! }));
    const hasInvalidFulfillQuantity = fulfillmentRows.some(({ item, remaining }) => {
        const quantity = quantities[item.id] ?? "";
        return (
            remaining > 0 &&
            selectedFulfillItems[item.id] === true &&
            (!isPositiveProductQuantity(quantity) || Number(quantity) > remaining)
        );
    });
    const orderedQuantity = order.items.reduce((total, item) => total + Number(item.quantity), 0);
    const reservedQuantity = fulfillmentRows.reduce(
        (total, { item }) =>
            total +
            (item.reservation
                ? Number(item.reservation.original_quantity) -
                  Number(item.reservation.released_quantity)
                : 0),
        0,
    );
    const shippedQuantity = fulfillmentRows.reduce(
        (total, { item }) => total + Number(item.reservation?.consumed_quantity ?? 0),
        0,
    );
    const remainingQuantity = fulfillmentRows.reduce(
        (total, { remaining }) => total + Math.max(0, remaining),
        0,
    );
    const canFulfill =
        order.order_source !== "cart" &&
        (order.order_status === "confirmed" || order.order_status === "processing") &&
        remainingQuantity > 0;
    const canCancel =
        order.order_status === "draft" ||
        (order.order_source === "cart"
            ? ["pending", "confirmed", "preparing"].includes(order.order_status)
            : order.order_status === "confirmed");
    const latestPayment = paymentsQuery.data?.data.payments[0];
    const shippingAddress = [
        order.shipping_address_line1,
        order.shipping_address_line2,
        order.shipping_district,
        order.shipping_city,
        order.shipping_province,
        order.shipping_country,
        order.shipping_postal_code,
    ]
        .filter(Boolean)
        .join(", ");

    return (
        <ProductAdminGuard>
            <div className="mx-auto max-w-7xl space-y-5">
                <header className="rounded-xl border bg-card p-4 sm:p-5">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0">
                            <Link
                                to={
                                    order.sales_channel === "dealer"
                                        ? "/admin/sales-orders/dealer"
                                        : "/admin/sales-orders/retail"
                                }
                                className={secondaryButtonClass}
                            >
                                ← Đơn hàng
                            </Link>
                            <h1 className="mt-4 text-2xl font-semibold text-primary sm:text-3xl">
                                {order.order_code}
                            </h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {order.dealer_name_snapshot ?? order.buyer.name}
                                {order.effective_tier_name_snapshot &&
                                    ` · Tier ${order.effective_tier_name_snapshot}`}
                                {` · ${salesOrderSourceLabel(order.order_source)}`}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {new Date(order.created_at).toLocaleString("vi-VN")}
                                {order.external_reference &&
                                    ` · Tham chiếu ${order.external_reference}`}
                                {order.dealer_code_snapshot && ` · ${order.dealer_code_snapshot}`}
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
                            </div>
                        </div>
                        <div className="flex flex-col items-start gap-2 sm:items-end">
                            <p className="text-2xl font-semibold text-primary">
                                {money(order.grand_total)}
                            </p>
                            {canCancel && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger
                                        className={secondaryButtonClass}
                                        disabled={Boolean(busy)}
                                    >
                                        ⋯ Thao tác
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            className="text-destructive focus:text-destructive"
                                            onSelect={cancel}
                                        >
                                            Hủy đơn hàng
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                        </div>
                    </div>
                </header>
                {notice && (
                    <p
                        role="alert"
                        className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700"
                    >
                        {notice}
                    </p>
                )}
                {stalePrice && (
                    <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm">
                        Giá bản nháp đã cũ. Bấm “Cập nhật giá” rồi kiểm tra tổng mới trước khi xác
                        nhận.
                    </p>
                )}
                <section className="rounded-xl border bg-card p-5">
                    <h2 className="text-lg font-semibold text-primary">Sản phẩm trong đơn</h2>
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[660px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="py-2">SKU</th>
                                    <th>Sản phẩm</th>
                                    <th>Số lượng</th>
                                    <th>
                                        Đơn giá{" "}
                                        {order.sales_channel === "dealer" ? "đại lý" : "Retail"}
                                    </th>
                                    <th>Giảm</th>
                                    <th>Thành tiền</th>
                                    <th>Đã xuất</th>
                                </tr>
                            </thead>
                            <tbody>
                                {order.items.map((item) => (
                                    <tr key={item.id} className="border-b last:border-0">
                                        <td className="py-3 font-mono">
                                            {item.sku_snapshot}
                                            {order.sales_channel === "dealer" &&
                                                item.minimum_quantity_snapshot && (
                                                    <span
                                                        className="mt-1 block text-xs text-muted-foreground"
                                                        title="Số lượng đặt tối thiểu tại thời điểm tạo đơn"
                                                    >
                                                        MOQ{" "}
                                                        {formatProductQuantity(
                                                            item.minimum_quantity_snapshot,
                                                        )}
                                                    </span>
                                                )}
                                        </td>
                                        <td>
                                            <div className="flex items-center gap-2">
                                                {item.image_url && (
                                                    <img
                                                        src={item.image_url}
                                                        alt=""
                                                        className="h-10 w-10 shrink-0 rounded object-cover"
                                                    />
                                                )}
                                                <span>
                                                    {item.product_name_snapshot} ·{" "}
                                                    {item.variant_name_snapshot}
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            {formatProductQuantity(item.quantity)}{" "}
                                            {item.unit_name_snapshot}
                                        </td>
                                        <td>{money(item.unit_price_snapshot)}</td>
                                        <td>{money(item.discount_amount)}</td>
                                        <td>{money(item.line_total)}</td>
                                        <td>
                                            {formatProductQuantity(
                                                item.reservation?.consumed_quantity ?? "0",
                                            )}
                                            /{formatProductQuantity(item.quantity)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
                <div className="grid gap-5 lg:grid-cols-2">
                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="text-lg font-semibold text-primary">
                            Khách hàng & giao nhận
                        </h2>
                        <dl className="mt-4 space-y-3 text-sm">
                            <div>
                                <dt className="text-muted-foreground">Khách hàng / đại lý</dt>
                                <dd className="font-medium">
                                    {order.dealer_name_snapshot ?? order.buyer.name}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Liên hệ</dt>
                                <dd>
                                    {order.recipient_phone} · {order.buyer.email}
                                </dd>
                            </div>
                            {(order.recipient_name !==
                                (order.dealer_name_snapshot ?? order.buyer.name) ||
                                (order.recipient_email &&
                                    order.recipient_email !== order.buyer.email)) && (
                                <div>
                                    <dt className="text-muted-foreground">Người nhận</dt>
                                    <dd>
                                        {order.recipient_name}
                                        {order.recipient_email &&
                                        order.recipient_email !== order.buyer.email
                                            ? ` · ${order.recipient_email}`
                                            : ""}
                                    </dd>
                                </div>
                            )}
                            <div>
                                <dt className="text-muted-foreground">Địa chỉ giao hàng</dt>
                                <dd className="break-words">{shippingAddress}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Phương thức thanh toán</dt>
                                <dd>
                                    {order.payment_method
                                        ? (paymentMethodText[order.payment_method] ??
                                          order.payment_method)
                                        : "—"}
                                </dd>
                            </div>
                            {order.delivery_note && (
                                <div>
                                    <dt className="text-muted-foreground">Ghi chú</dt>
                                    <dd>{order.delivery_note}</dd>
                                </div>
                            )}
                        </dl>
                    </section>
                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="text-lg font-semibold text-primary">Tổng đơn</h2>
                        <dl className="mt-4 space-y-2 text-sm">
                            {(
                                [
                                    ["Tiền hàng", order.subtotal],
                                    ["Giảm giá", order.discount_total],
                                    ["Thuế", order.tax_total],
                                    ["Vận chuyển", order.shipping_total],
                                ] as const
                            ).map(([label, value]) => (
                                <div key={label} className="flex justify-between gap-4">
                                    <dt>{label}</dt>
                                    <dd className="tabular-nums">{money(value)}</dd>
                                </div>
                            ))}
                            {(order.promotion_name_snapshot || order.voucher_code_snapshot) && (
                                <p className="text-xs text-muted-foreground">
                                    Ưu đãi:{" "}
                                    {order.promotion_name_snapshot
                                        ? `${order.promotion_name_snapshot} (${order.promotion_code_snapshot})`
                                        : order.voucher_code_snapshot}
                                </p>
                            )}
                            <div className="flex justify-between gap-4 border-t pt-3 text-base font-semibold text-primary">
                                <dt>Tổng</dt>
                                <dd className="tabular-nums">{money(order.grand_total)}</dd>
                            </div>
                            {order.cogs_total !== null && order.cogs_total !== undefined && (
                                <div className="flex justify-between gap-4 text-muted-foreground">
                                    <dt>Giá vốn đã xuất</dt>
                                    <dd className="tabular-nums">{money(order.cogs_total)}</dd>
                                </div>
                            )}
                            {order.gross_profit !== null && order.gross_profit !== undefined && (
                                <div className="flex justify-between gap-4 font-semibold">
                                    <dt>Lợi nhuận gộp</dt>
                                    <dd className="tabular-nums">{money(order.gross_profit)}</dd>
                                </div>
                            )}
                            <div className="flex justify-between gap-4">
                                <dt>Đã thanh toán</dt>
                                <dd className="tabular-nums">{money(order.paid_amount)}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt>Còn phải thu</dt>
                                <dd className="tabular-nums">{money(order.outstanding_amount)}</dd>
                            </div>
                        </dl>
                    </section>
                </div>
                <section className="rounded-xl border bg-card p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold text-primary">Thanh toán</h2>
                        <SalesOrderStatusBadge kind="payment" status={order.payment_status} />
                    </div>
                    <div className="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                        <p>
                            Tổng đơn
                            <br />
                            <strong>{money(order.grand_total)}</strong>
                        </p>
                        <p>
                            Đã nhận
                            <br />
                            <strong>{money(order.paid_amount)}</strong>
                        </p>
                        <p>
                            Còn phải thu
                            <br />
                            <strong>{money(order.outstanding_amount)}</strong>
                        </p>
                    </div>
                    <p className="mt-3 text-sm text-muted-foreground">
                        {order.payment_method
                            ? (paymentMethodText[order.payment_method] ?? order.payment_method)
                            : "Chưa chọn phương thức"}
                        {latestPayment &&
                            ` · Gần nhất ${money(latestPayment.amount)} · ${new Date(latestPayment.settled_at ?? latestPayment.created_at).toLocaleString("vi-VN")}`}
                    </p>
                    {paymentsQuery.isError && (
                        <p role="alert" className="mt-2 text-sm text-red-700">
                            {errorMessage(paymentsQuery.error)}
                        </p>
                    )}
                    <div className="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            className={secondaryButtonClass}
                            onClick={() => setPaymentHistoryOpen(true)}
                        >
                            Xem lịch sử thanh toán
                        </button>
                        {canRecordPayment && (
                            <button
                                type="button"
                                className={buttonClass}
                                disabled={Boolean(busy)}
                                onClick={() => {
                                    setNotice("");
                                    setPaymentAmount(order.outstanding_amount);
                                    setPaymentOpen(true);
                                }}
                            >
                                Ghi nhận thanh toán
                            </button>
                        )}
                    </div>
                </section>
                {order.order_status === "draft" && (
                    <section className="flex flex-wrap gap-3 rounded-xl border bg-card p-5">
                        {order.sales_channel === "retail" && order.order_source === "admin" && (
                            <Link
                                to="/admin/sales-orders/$id/edit"
                                params={{ id: String(order.id) }}
                                className={secondaryButtonClass}
                            >
                                Chỉnh sửa bản nháp
                            </Link>
                        )}
                        <button
                            type="button"
                            className={secondaryButtonClass}
                            disabled={Boolean(busy)}
                            onClick={() =>
                                action("reprice", "", (key) => salesOrderApi.reprice(id, key))
                            }
                        >
                            {busy === "reprice" ? "Đang cập nhật..." : "Cập nhật giá"}
                        </button>
                        <button
                            type="button"
                            className={buttonClass}
                            disabled={Boolean(busy)}
                            onClick={() => {
                                setDialog({
                                    title: `Xác nhận đơn ${order.order_code} và giữ hàng?`,
                                    execute: () =>
                                        action("confirm", "", (key) =>
                                            salesOrderApi.confirm(id, key),
                                        ),
                                });
                            }}
                        >
                            {busy === "confirm" ? "Đang xác nhận..." : "Xác nhận & giữ hàng"}
                        </button>
                    </section>
                )}
                <section className="rounded-xl border bg-card p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold text-primary">Kho & xuất hàng</h2>
                        <SalesOrderStatusBadge
                            kind="fulfillment"
                            status={order.fulfillment_status}
                        />
                    </div>
                    <p className="mt-3 text-sm">
                        Kho: <strong>{order.warehouse.name}</strong> · {order.warehouse.code}
                    </p>
                    <div className="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                        <p>
                            Đã giữ
                            <br />
                            <strong>
                                {formatProductQuantity(reservedQuantity)}/
                                {formatProductQuantity(orderedQuantity)}
                            </strong>
                        </p>
                        <p>
                            Đã xuất
                            <br />
                            <strong>
                                {formatProductQuantity(shippedQuantity)}/
                                {formatProductQuantity(orderedQuantity)}
                            </strong>
                        </p>
                        <p>
                            Còn chờ xuất
                            <br />
                            <strong>{formatProductQuantity(remainingQuantity)}</strong>
                        </p>
                    </div>
                    <div className="mt-4 flex flex-wrap gap-2">
                        {canFulfill && (
                            <button
                                type="button"
                                className={buttonClass}
                                disabled={Boolean(busy)}
                                onClick={() => {
                                    setQuantities(
                                        Object.fromEntries(
                                            fulfillmentRows
                                                .filter(({ remaining }) => remaining > 0)
                                                .map(({ item, remaining }) => [
                                                    item.id,
                                                    String(remaining),
                                                ]),
                                        ),
                                    );
                                    setSelectedFulfillItems(
                                        Object.fromEntries(
                                            fulfillmentRows
                                                .filter(({ remaining }) => remaining > 0)
                                                .map(({ item }) => [item.id, true]),
                                        ),
                                    );
                                    setFulfillmentOpen(true);
                                }}
                            >
                                Xuất hàng
                            </button>
                        )}
                        <button
                            type="button"
                            className={secondaryButtonClass}
                            onClick={() => setFulfillmentHistoryOpen(true)}
                        >
                            Xem lịch sử xuất hàng
                        </button>
                    </div>
                </section>
                {order.sales_channel === "retail" &&
                    order.order_source === "cart" &&
                    ["pending", "confirmed", "preparing", "shipping"].includes(
                        order.order_status,
                    ) && (
                        <section className="space-y-5 rounded-xl border bg-card p-5">
                            <h2 className="text-xl text-primary">Xử lý đơn</h2>
                            <div className="flex flex-wrap gap-3">
                                {(
                                    [
                                        ["pending", "Xác nhận & giữ hàng", "confirm"],
                                        ["confirmed", "Chuẩn bị hàng", "preparing"],
                                        ["preparing", "Xuất kho & giao hàng", "shipping"],
                                        ["shipping", "Xác nhận đã giao", "delivered"],
                                    ] as const
                                )
                                    .filter(([from]) => order.order_status === from)
                                    .map(([, label, target]) => (
                                        <button
                                            key={target}
                                            type="button"
                                            className={buttonClass}
                                            disabled={Boolean(busy)}
                                            onClick={() =>
                                                setDialog({
                                                    title: `${label} cho đơn ${order.order_code}?`,
                                                    execute: () =>
                                                        action(target, "", (key) =>
                                                            target === "confirm"
                                                                ? salesOrderApi.confirm(id, key)
                                                                : salesOrderApi.advance(
                                                                      id,
                                                                      key,
                                                                      target,
                                                                  ),
                                                        ),
                                                })
                                            }
                                        >
                                            {busy === target ? "Đang xử lý..." : label}
                                        </button>
                                    ))}
                            </div>
                            {["pending", "confirmed", "preparing"].includes(order.order_status) && (
                                <button
                                    type="button"
                                    className={secondaryButtonClass}
                                    onClick={() => setWarehouseOpen(true)}
                                >
                                    Đổi kho xuất hàng
                                </button>
                            )}
                        </section>
                    )}
                <ReturnRefundSection order={order} />
                {order.histories?.length > 0 && (
                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="text-lg font-semibold text-primary">Lịch sử đơn</h2>
                        <ol className="mt-4 space-y-3 border-l pl-4 text-sm">
                            {order.histories
                                .slice(-4)
                                .reverse()
                                .map((entry) => (
                                    <li
                                        key={entry.id}
                                        className="relative pb-2 before:absolute before:-left-[21px] before:top-1 before:h-2.5 before:w-2.5 before:rounded-full before:bg-primary"
                                    >
                                        <strong>
                                            {entry.to_status
                                                ? salesOrderTimelineLabel(entry.to_status)
                                                : entry.event_type}
                                        </strong>
                                        <p className="text-xs text-muted-foreground">
                                            {entry.actor?.name ?? "Hệ thống"} ·{" "}
                                            {new Date(entry.created_at).toLocaleString("vi-VN")}
                                        </p>
                                        {entry.note && <p className="mt-1">{entry.note}</p>}
                                    </li>
                                ))}
                        </ol>
                        {order.histories.length > 4 && (
                            <button
                                type="button"
                                className="mt-3 text-sm font-medium text-primary underline underline-offset-2"
                                onClick={() => setOrderHistoryOpen(true)}
                            >
                                Xem toàn bộ lịch sử
                            </button>
                        )}
                    </section>
                )}
            </div>
            <Dialog open={warehouseOpen} onOpenChange={(open) => !busy && setWarehouseOpen(open)}>
                <DialogContent className="w-[calc(100%-2rem)] max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Đổi kho xuất hàng</DialogTitle>
                        <DialogDescription>
                            Đơn {order.order_code} · Kho hiện tại: {order.warehouse.name}
                        </DialogDescription>
                    </DialogHeader>
                    <label className="grid gap-1 text-sm">
                        <span>Kho mới</span>
                        <select
                            className={fieldClass}
                            value={selectedWarehouse || String(order.warehouse_id)}
                            onChange={(event) => setSelectedWarehouse(event.target.value)}
                        >
                            {warehouses.data?.data.map((warehouse) => (
                                <option key={warehouse.id} value={warehouse.id}>
                                    {warehouse.code} · {warehouse.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <DialogFooter className="gap-2">
                        <button
                            type="button"
                            className={secondaryButtonClass}
                            disabled={Boolean(busy)}
                            onClick={() => setWarehouseOpen(false)}
                        >
                            Hủy
                        </button>
                        <button
                            type="button"
                            className={buttonClass}
                            disabled={
                                Boolean(busy) ||
                                !selectedWarehouse ||
                                Number(selectedWarehouse) === order.warehouse_id
                            }
                            onClick={() =>
                                setDialog({
                                    title: `Chuyển đơn ${order.order_code} sang kho mới?`,
                                    execute: async () => {
                                        const succeeded = await action(
                                            "warehouse",
                                            selectedWarehouse,
                                            (key) =>
                                                salesOrderApi.warehouse(
                                                    id,
                                                    key,
                                                    Number(selectedWarehouse),
                                                ),
                                        );
                                        if (succeeded) setWarehouseOpen(false);
                                        return succeeded;
                                    },
                                })
                            }
                        >
                            Xác nhận đổi kho
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Dialog
                open={fulfillmentOpen}
                onOpenChange={(open) => !busy && setFulfillmentOpen(open)}
            >
                <DialogContent className="max-h-[90vh] w-[calc(100%-2rem)] max-w-4xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Xuất hàng · {order.order_code}</DialogTitle>
                        <DialogDescription>
                            Kho xuất: {order.warehouse.name}. Chọn SKU và số lượng xuất từ phần đã
                            giữ.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="py-2">Chọn</th>
                                    <th>Sản phẩm / SKU</th>
                                    <th>Đã giữ</th>
                                    <th>Đã xuất</th>
                                    <th>Còn lại</th>
                                    <th>Xuất lần này</th>
                                </tr>
                            </thead>
                            <tbody>
                                {fulfillmentRows.map(({ item, remaining }) => (
                                    <tr key={item.id} className="border-b last:border-0">
                                        <td className="py-3">
                                            <input
                                                type="checkbox"
                                                aria-label={`Chọn ${item.sku_snapshot}`}
                                                disabled={remaining <= 0 || Boolean(busy)}
                                                checked={
                                                    remaining > 0 &&
                                                    selectedFulfillItems[item.id] === true
                                                }
                                                onChange={(event) => {
                                                    setSelectedFulfillItems((current) => ({
                                                        ...current,
                                                        [item.id]: event.target.checked,
                                                    }));
                                                    setQuantities((current) => ({
                                                        ...current,
                                                        [item.id]: event.target.checked
                                                            ? String(remaining)
                                                            : "",
                                                    }));
                                                }}
                                            />
                                        </td>
                                        <td>
                                            <strong>
                                                {item.product_name_snapshot} ·{" "}
                                                {item.variant_name_snapshot}
                                            </strong>
                                            <span className="block font-mono text-xs text-muted-foreground">
                                                {item.sku_snapshot}
                                            </span>
                                        </td>
                                        <td>
                                            {formatProductQuantity(
                                                item.reservation?.original_quantity ?? 0,
                                            )}{" "}
                                            {item.unit_name_snapshot}
                                        </td>
                                        <td>
                                            {formatProductQuantity(
                                                item.reservation?.consumed_quantity ?? 0,
                                            )}
                                        </td>
                                        <td>{formatProductQuantity(Math.max(0, remaining))}</td>
                                        <td>
                                            <input
                                                aria-label={`Số lượng xuất ${item.sku_snapshot}`}
                                                type="number"
                                                min="1"
                                                max={remaining}
                                                step="1"
                                                className={`${fieldClass} w-28`}
                                                disabled={
                                                    remaining <= 0 ||
                                                    selectedFulfillItems[item.id] !== true ||
                                                    Boolean(busy)
                                                }
                                                value={
                                                    remaining > 0 ? (quantities[item.id] ?? "") : ""
                                                }
                                                onChange={(event) =>
                                                    setQuantities((current) => ({
                                                        ...current,
                                                        [item.id]: event.target.value,
                                                    }))
                                                }
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {hasInvalidFulfillQuantity && (
                        <p role="alert" className="text-sm text-red-700">
                            Số lượng xuất phải là số nguyên dương và không vượt phần còn giữ.
                        </p>
                    )}
                    <DialogFooter className="gap-2">
                        <button
                            type="button"
                            className={secondaryButtonClass}
                            disabled={Boolean(busy)}
                            onClick={() => {
                                setSelectedFulfillItems(
                                    Object.fromEntries(
                                        fulfillmentRows
                                            .filter(({ remaining }) => remaining > 0)
                                            .map(({ item }) => [item.id, true]),
                                    ),
                                );
                                setQuantities(
                                    Object.fromEntries(
                                        fulfillmentRows
                                            .filter(({ remaining }) => remaining > 0)
                                            .map(({ item, remaining }) => [
                                                item.id,
                                                String(remaining),
                                            ]),
                                    ),
                                );
                            }}
                        >
                            Chọn tất cả còn lại
                        </button>
                        <button
                            type="button"
                            className={secondaryButtonClass}
                            disabled={Boolean(busy)}
                            onClick={() => setFulfillmentOpen(false)}
                        >
                            Hủy
                        </button>
                        <button
                            type="button"
                            className={buttonClass}
                            disabled={
                                Boolean(busy) ||
                                fulfillItems.length === 0 ||
                                hasInvalidFulfillQuantity
                            }
                            onClick={() =>
                                setDialog({
                                    title: `Xuất hàng cho đơn ${order.order_code}?`,
                                    description: `Xác nhận xuất ${fulfillItems.length} SKU từ ${order.warehouse.name}.`,
                                    execute: async () => {
                                        const succeeded = await action(
                                            "fulfill",
                                            JSON.stringify(fulfillItems),
                                            (key) => salesOrderApi.fulfill(id, key, fulfillItems),
                                        );
                                        if (succeeded) setFulfillmentOpen(false);
                                        return succeeded;
                                    },
                                })
                            }
                        >
                            {busy === "fulfill" ? "Đang xuất..." : "Xác nhận xuất hàng"}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Sheet open={paymentHistoryOpen} onOpenChange={setPaymentHistoryOpen}>
                <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                    <SheetHeader>
                        <SheetTitle>Lịch sử thanh toán</SheetTitle>
                        <SheetDescription>Đơn {order.order_code}</SheetDescription>
                    </SheetHeader>
                    {paymentsQuery.isPending && <p className="mt-5 text-sm">Đang tải...</p>}
                    {paymentsQuery.isError && (
                        <p role="alert" className="mt-5 text-sm text-red-700">
                            {errorMessage(paymentsQuery.error)}
                        </p>
                    )}
                    {paymentsQuery.data?.data.payments.length === 0 && (
                        <p className="mt-5 text-sm text-muted-foreground">
                            Chưa có giao dịch thanh toán.
                        </p>
                    )}
                    <ol className="mt-5 space-y-3">
                        {paymentsQuery.data?.data.payments.map((payment) => (
                            <li key={payment.id} className="rounded-lg border p-4 text-sm">
                                <div className="flex justify-between gap-3">
                                    <strong>{money(payment.amount)}</strong>
                                    <span>
                                        {payment.status === "settled"
                                            ? "Đã quyết toán"
                                            : payment.status}
                                    </span>
                                </div>
                                <p className="mt-1 font-mono">{payment.payment_code}</p>
                                <p>
                                    {paymentMethodText[payment.payment_method] ??
                                        payment.payment_method}{" "}
                                    ·{" "}
                                    {new Date(
                                        payment.settled_at ?? payment.created_at,
                                    ).toLocaleString("vi-VN")}
                                </p>
                                {payment.external_reference && (
                                    <p>Tham chiếu: {payment.external_reference}</p>
                                )}
                                {payment.note && <p>Ghi chú: {payment.note}</p>}
                                <p className="text-muted-foreground">
                                    Người ghi nhận: {payment.recorded_by?.name ?? "Hệ thống"}
                                </p>
                            </li>
                        ))}
                    </ol>
                </SheetContent>
            </Sheet>
            <Sheet open={fulfillmentHistoryOpen} onOpenChange={setFulfillmentHistoryOpen}>
                <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                    <SheetHeader>
                        <SheetTitle>Lịch sử kho & xuất hàng</SheetTitle>
                        <SheetDescription>
                            Đơn {order.order_code} · {order.warehouse.name}
                        </SheetDescription>
                    </SheetHeader>
                    <ol className="mt-5 space-y-3">
                        {order.histories
                            .filter((entry) =>
                                [
                                    "confirmed",
                                    "fulfilled",
                                    "shipping",
                                    "warehouse_changed",
                                ].includes(entry.event_type),
                            )
                            .slice()
                            .reverse()
                            .map((entry) => (
                                <li key={entry.id} className="rounded-lg border p-4 text-sm">
                                    <strong>
                                        {entry.event_type === "fulfilled" ||
                                        entry.event_type === "shipping"
                                            ? "Xuất hàng"
                                            : entry.event_type === "warehouse_changed"
                                              ? "Đổi kho"
                                              : "Xác nhận & giữ hàng"}
                                    </strong>
                                    <p>
                                        {new Date(entry.created_at).toLocaleString("vi-VN")} ·{" "}
                                        {entry.actor?.name ?? "Hệ thống"}
                                    </p>
                                    {entry.note && (
                                        <p className="mt-1 text-muted-foreground">{entry.note}</p>
                                    )}
                                </li>
                            ))}
                    </ol>
                    {!order.histories.some((entry) =>
                        ["confirmed", "fulfilled", "shipping", "warehouse_changed"].includes(
                            entry.event_type,
                        ),
                    ) && (
                        <p className="mt-5 text-sm text-muted-foreground">
                            Chưa có sự kiện kho hoặc xuất hàng.
                        </p>
                    )}
                </SheetContent>
            </Sheet>
            <Sheet open={orderHistoryOpen} onOpenChange={setOrderHistoryOpen}>
                <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                    <SheetHeader>
                        <SheetTitle>Toàn bộ lịch sử đơn</SheetTitle>
                        <SheetDescription>{order.order_code}</SheetDescription>
                    </SheetHeader>
                    <ol className="mt-5 space-y-3 border-l pl-4 text-sm">
                        {order.histories
                            .slice()
                            .reverse()
                            .map((entry) => (
                                <li
                                    key={entry.id}
                                    className="relative pb-2 before:absolute before:-left-[21px] before:top-1 before:h-2.5 before:w-2.5 before:rounded-full before:bg-primary"
                                >
                                    <strong>
                                        {entry.event_type === "warehouse_changed"
                                            ? "Đổi kho"
                                            : entry.to_status
                                              ? salesOrderTimelineLabel(entry.to_status)
                                              : entry.event_type}
                                    </strong>
                                    <p className="text-xs text-muted-foreground">
                                        {entry.actor?.name ?? "Hệ thống"} ·{" "}
                                        {new Date(entry.created_at).toLocaleString("vi-VN")}
                                    </p>
                                    {entry.note && <p className="mt-1">{entry.note}</p>}
                                </li>
                            ))}
                    </ol>
                </SheetContent>
            </Sheet>
            <Dialog open={paymentOpen} onOpenChange={(open) => !busy && setPaymentOpen(open)}>
                <DialogContent className="w-[calc(100%-2rem)] max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Ghi nhận thanh toán</DialogTitle>
                        <DialogDescription>
                            Đơn {order.order_code} · Còn phải thu {money(order.outstanding_amount)}.
                            Chỉ xác nhận khi đã nhận tiền.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void recordPayment();
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            <span>Số tiền đã nhận *</span>
                            <input
                                className={fieldClass}
                                inputMode="decimal"
                                value={paymentAmount}
                                onChange={(event) => setPaymentAmount(event.target.value)}
                                placeholder={order.outstanding_amount}
                            />
                            {paymentAmount && !paymentAmountValid && (
                                <span className="text-xs text-red-700">
                                    Số tiền phải lớn hơn 0 và không vượt số tiền còn phải thu.
                                </span>
                            )}
                        </label>
                        <label className="grid gap-1 text-sm">
                            <span>Phương thức *</span>
                            <select
                                className={fieldClass}
                                value={paymentMethod}
                                onChange={(event) =>
                                    setPaymentMethod(event.target.value as typeof paymentMethod)
                                }
                            >
                                <option value="bank_transfer">Chuyển khoản</option>
                                <option value="cash">Tiền mặt</option>
                                <option value="other_manual">Thủ công khác</option>
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            <span>Mã tham chiếu</span>
                            <input
                                className={fieldClass}
                                maxLength={255}
                                value={paymentReference}
                                onChange={(event) => setPaymentReference(event.target.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            <span>Ghi chú</span>
                            <textarea
                                className={fieldClass}
                                maxLength={1000}
                                value={paymentNote}
                                onChange={(event) => setPaymentNote(event.target.value)}
                            />
                        </label>
                        {notice && (
                            <p role="alert" className="text-sm text-red-700">
                                {notice}
                            </p>
                        )}
                        <DialogFooter className="gap-2">
                            <button
                                type="button"
                                className={secondaryButtonClass}
                                disabled={Boolean(busy)}
                                onClick={() => setPaymentOpen(false)}
                            >
                                Hủy
                            </button>
                            <button
                                type="submit"
                                className={buttonClass}
                                disabled={Boolean(busy) || !paymentAmountValid}
                            >
                                {busy === "payment" ? "Đang ghi nhận..." : "Xác nhận thanh toán"}
                            </button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <AlertDialog
                open={dialog !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) {
                        setDialog(null);
                        setCancelReason("");
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{dialog?.title}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {dialog?.description ??
                                "Thao tác sẽ cập nhật trạng thái đơn và tồn kho tương ứng."}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {dialog?.needsReason && (
                        <label className="grid gap-1 text-sm">
                            <span>Lý do hủy *</span>
                            <textarea
                                className={fieldClass}
                                maxLength={1000}
                                value={cancelReason}
                                onChange={(event) => setCancelReason(event.target.value)}
                            />
                        </label>
                    )}
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={Boolean(busy)}>Quay lại</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={
                                Boolean(busy) ||
                                Boolean(dialog?.needsReason && !cancelReason.trim())
                            }
                            onClick={async (event) => {
                                event.preventDefault();
                                if (await dialog?.execute(cancelReason.trim())) {
                                    setDialog(null);
                                    setCancelReason("");
                                }
                            }}
                        >
                            {busy ? "Đang xử lý..." : "Xác nhận"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </ProductAdminGuard>
    );
}
