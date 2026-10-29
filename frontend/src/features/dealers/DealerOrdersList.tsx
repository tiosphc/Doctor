import { SalesOrderStatusBadge } from "@/components/common/SalesOrderStatusBadge";
import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useNavigate, Link } from "@tanstack/react-router";
import { ChevronRight, MoreHorizontal, Search } from "lucide-react";
import { ErrorState, Pagination } from "@/components/common/AsyncState";
import { salesOrderSourceLabel, salesOrderStatusLabel } from "@/components/common/salesOrderStatus";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { formatProductQuantity } from "@/lib/productQuantity";
import { errorMessage } from "@/services/api";
import { dealerApi, dealerKeys } from "./api";
import type { DealerOrderFilters } from "./api";
import type { DealerOrder } from "./types";

const money = (value: string) => `${new Intl.NumberFormat("vi-VN").format(Number(value))} ₫`;
const tabs = [
    { key: "all", label: "Tất cả" },
    { key: "pending", label: "Chờ xác nhận" },
    { key: "active", label: "Đang xử lý" },
    { key: "completed", label: "Hoàn thành" },
    { key: "cancelled", label: "Đã hủy" },
] as const;
type Tab = (typeof tabs)[number]["key"];

function shortLocation(order: DealerOrder): string {
    const locality = order.shipping_district || order.shipping_ward || order.shipping_city;
    return [locality, locality === order.shipping_province ? null : order.shipping_province]
        .filter(Boolean)
        .join(", ");
}

function itemSummary(order: DealerOrder): string {
    return `${order.item_count ?? 0} SKU · ${formatProductQuantity(order.total_quantity ?? "0")} SP`;
}

function OrderActions({ order }: { order: DealerOrder }) {
    const navigate = useNavigate();
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={`Thao tác đơn ${order.order_code}`}
                    className="grid size-9 place-items-center rounded-md border text-muted-foreground hover:bg-accent hover:text-primary"
                    onClick={(event) => event.stopPropagation()}
                    onKeyDown={(event) => event.stopPropagation()}
                >
                    <MoreHorizontal className="size-4" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" onClick={(event) => event.stopPropagation()}>
                <DropdownMenuItem
                    onSelect={(event) => {
                        event.stopPropagation();
                        void navigate({
                            to: "/dealer/orders/$orderId",
                            params: { orderId: String(order.id) },
                        });
                    }}
                >
                    Xem chi tiết
                </DropdownMenuItem>
                {(order.item_count ?? 0) > 0 && (
                    <DropdownMenuItem
                        onSelect={(event) => {
                            event.stopPropagation();
                            void navigate({
                                to: "/dealer/quick-order",
                                search: { sku: "", reorder: order.id },
                            });
                        }}
                    >
                        Đặt lại đơn
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function OrderRow({ order, layout }: { order: DealerOrder; layout: "table" | "card" }) {
    const navigate = useNavigate();
    const open = () =>
        void navigate({ to: "/dealer/orders/$orderId", params: { orderId: String(order.id) } });
    const keyboardOpen = (event: React.KeyboardEvent<HTMLElement>) => {
        if (event.target === event.currentTarget && (event.key === "Enter" || event.key === " ")) {
            event.preventDefault();
            open();
        }
    };
    const source = salesOrderSourceLabel(order.order_source);
    return layout === "table" ? (
        <tr
            role="link"
            tabIndex={0}
            aria-label={`Xem đơn ${order.order_code}`}
            className="cursor-pointer border-b transition-colors hover:bg-muted/40 focus-visible:bg-muted/50 focus-visible:outline-2 focus-visible:outline-primary last:border-0"
            onClick={open}
            onKeyDown={keyboardOpen}
        >
            <td className="px-4 py-3.5 align-middle">
                <strong className="text-sm font-semibold text-primary">{order.order_code}</strong>
                {order.external_reference && (
                    <p
                        className="dealer-meta max-w-44 truncate text-muted-foreground"
                        title={order.external_reference}
                    >
                        #{order.external_reference}
                    </p>
                )}
                <span className="dealer-meta mt-1 inline-block rounded-full bg-muted px-2.5 py-0.5 text-muted-foreground">
                    {source}
                </span>
            </td>
            <td className="px-4 py-3.5 align-middle">
                <strong className="text-sm font-semibold">{order.recipient_name}</strong>
                <p className="dealer-meta text-muted-foreground">{order.recipient_phone}</p>
                <p
                    className="dealer-meta max-w-44 truncate text-muted-foreground"
                    title={shortLocation(order)}
                >
                    {shortLocation(order)}
                </p>
            </td>
            <td className="px-4 py-3.5 align-middle text-sm">
                {new Date(order.created_at).toLocaleDateString("vi-VN")}
            </td>
            <td className="px-4 py-3.5 align-middle text-sm">{itemSummary(order)}</td>
            <td className="px-4 py-3.5 align-middle">
                <SalesOrderStatusBadge kind="payment" status={order.payment_status} />
            </td>
            <td className="px-4 py-3.5 align-middle">
                <SalesOrderStatusBadge kind="order" status={order.order_status} />
                <p className="dealer-meta mt-1 text-muted-foreground">
                    {salesOrderStatusLabel("fulfillment", order.fulfillment_status)}
                </p>
            </td>
            <td className="px-4 py-3.5 text-right align-middle text-[15px] font-bold whitespace-nowrap text-primary">
                {money(order.grand_total)}
            </td>
            <td className="px-2 py-3.5 align-middle">
                <OrderActions order={order} />
            </td>
        </tr>
    ) : (
        <article
            role="link"
            tabIndex={0}
            aria-label={`Xem đơn ${order.order_code}`}
            className="cursor-pointer rounded-xl border bg-card p-4 transition-colors hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-primary 2xl:hidden"
            onClick={open}
            onKeyDown={keyboardOpen}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <strong className="text-primary">{order.order_code}</strong>
                    {order.external_reference && (
                        <p className="dealer-meta truncate text-muted-foreground">
                            #{order.external_reference}
                        </p>
                    )}
                    <span className="dealer-meta text-muted-foreground">{source}</span>
                </div>
                <strong className="text-right text-primary whitespace-nowrap">
                    {money(order.grand_total)}
                </strong>
            </div>
            <p className="dealer-meta mt-2 text-muted-foreground">
                {new Date(order.created_at).toLocaleString("vi-VN")}
            </p>
            <div className="mt-3 space-y-0.5 text-sm">
                <strong>{order.recipient_name}</strong>
                <p>{order.recipient_phone}</p>
                <p className="text-muted-foreground">{shortLocation(order)}</p>
            </div>
            <p className="mt-3 text-sm">{itemSummary(order)}</p>
            <div className="mt-3 flex flex-wrap items-center gap-2">
                <SalesOrderStatusBadge kind="payment" status={order.payment_status} />
                <SalesOrderStatusBadge kind="order" status={order.order_status} />
            </div>
            <div className="dealer-meta mt-3 flex items-center justify-between gap-2 text-muted-foreground">
                <span>{salesOrderStatusLabel("fulfillment", order.fulfillment_status)}</span>
                <div className="flex items-center gap-2">
                    <OrderActions order={order} />
                    <ChevronRight className="size-4" aria-hidden="true" />
                </div>
            </div>
        </article>
    );
}

export function DealerOrdersList({
    accountId,
    userId,
}: {
    accountId: number;
    userId: number | undefined;
}) {
    const [page, setPage] = useState(1);
    const [tab, setTab] = useState<Tab>("all");
    const [searchInput, setSearchInput] = useState("");
    const [search, setSearch] = useState("");
    const [orderStatus, setOrderStatus] = useState("");
    const [paymentStatus, setPaymentStatus] = useState("");
    const [warehouseId, setWarehouseId] = useState("");
    const [dateFrom, setDateFrom] = useState("");
    const [dateTo, setDateTo] = useState("");
    useEffect(() => {
        const timer = window.setTimeout(() => {
            setSearch(searchInput.trim());
            setPage(1);
        }, 300);
        return () => window.clearTimeout(timer);
    }, [searchInput]);
    const filters: DealerOrderFilters = {
        page,
        ...(search ? { search } : {}),
        ...(orderStatus ? { order_status: orderStatus } : {}),
        ...(tab !== "all" ? { status_group: tab } : {}),
        ...(paymentStatus ? { payment_status: paymentStatus } : {}),
        ...(warehouseId ? { warehouse_id: Number(warehouseId) } : {}),
        ...(dateFrom ? { date_from: dateFrom } : {}),
        ...(dateTo ? { date_to: dateTo } : {}),
    };
    const orders = useQuery({
        queryKey: dealerKeys.orders(userId, accountId, filters),
        queryFn: () => dealerApi.orders(accountId, filters),
        retry: false,
    });
    const hasFilters = Boolean(
        searchInput ||
        orderStatus ||
        paymentStatus ||
        warehouseId ||
        dateFrom ||
        dateTo ||
        tab !== "all",
    );
    const clearFilters = () => {
        setSearchInput("");
        setSearch("");
        setOrderStatus("");
        setPaymentStatus("");
        setWarehouseId("");
        setDateFrom("");
        setDateTo("");
        setTab("all");
        setPage(1);
    };
    const fieldClass = "dealer-control w-full min-w-0 rounded-md border bg-background px-3 py-2";
    return (
        <section className="space-y-4">
            <div
                className="flex gap-2 overflow-x-auto border-b pb-2"
                role="tablist"
                aria-label="Lọc trạng thái đơn"
            >
                {tabs.map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        role="tab"
                        aria-selected={tab === item.key}
                        className={`shrink-0 rounded-full border px-4 py-2 text-sm font-medium transition-colors ${tab === item.key ? "border-primary bg-primary text-primary-foreground" : "bg-card text-muted-foreground hover:bg-muted"}`}
                        onClick={() => {
                            setTab(item.key);
                            setOrderStatus("");
                            setPage(1);
                        }}
                    >
                        {item.label}{" "}
                        <span className="dealer-meta ml-1 opacity-75">
                            {orders.data?.status_counts[item.key] ?? 0}
                        </span>
                    </button>
                ))}
            </div>
            <div className="space-y-4 rounded-xl border bg-card p-4 sm:p-5">
                <div className="relative">
                    <Search
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <input
                        className={`${fieldClass} pl-10`}
                        aria-label="Tìm đơn đại lý"
                        placeholder="Tìm mã đơn, tên hoặc SĐT người nhận..."
                        value={searchInput}
                        onChange={(event) => setSearchInput(event.target.value)}
                    />
                </div>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <label className="grid gap-1.5 text-sm font-medium text-foreground">
                        Từ ngày
                        <input
                            type="date"
                            className={fieldClass}
                            value={dateFrom}
                            max={dateTo || undefined}
                            onChange={(event) => {
                                setDateFrom(event.target.value);
                                setPage(1);
                            }}
                        />
                    </label>
                    <label className="grid gap-1.5 text-sm font-medium text-foreground">
                        Đến ngày
                        <input
                            type="date"
                            className={fieldClass}
                            value={dateTo}
                            min={dateFrom || undefined}
                            onChange={(event) => {
                                setDateTo(event.target.value);
                                setPage(1);
                            }}
                        />
                    </label>
                    <label className="grid gap-1.5 text-sm font-medium text-foreground">
                        Thanh toán
                        <select
                            className={fieldClass}
                            value={paymentStatus}
                            onChange={(event) => {
                                setPaymentStatus(event.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">Mọi thanh toán</option>
                            {[
                                "unpaid",
                                "pending",
                                "partially_paid",
                                "paid",
                                "partially_refunded",
                                "refunded",
                            ].map((value) => (
                                <option key={value} value={value}>
                                    {salesOrderStatusLabel("payment", value)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1.5 text-sm font-medium text-foreground">
                        Trạng thái đơn
                        <select
                            className={fieldClass}
                            value={orderStatus}
                            onChange={(event) => {
                                setOrderStatus(event.target.value);
                                setTab("all");
                                setPage(1);
                            }}
                        >
                            <option value="">Mọi trạng thái</option>
                            {[
                                "pending",
                                "confirmed",
                                "preparing",
                                "shipping",
                                "processing",
                                "delivered",
                                "completed",
                                "cancelled",
                            ].map((value) => (
                                <option key={value} value={value}>
                                    {salesOrderStatusLabel("order", value)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1.5 text-sm font-medium text-foreground">
                        Kho
                        <select
                            className={fieldClass}
                            value={warehouseId}
                            onChange={(event) => {
                                setWarehouseId(event.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">Mọi kho</option>
                            {orders.data?.warehouses.map((warehouse) => (
                                <option key={warehouse.id} value={warehouse.id}>
                                    {warehouse.name}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                {hasFilters && (
                    <button
                        type="button"
                        className="text-sm font-medium text-primary underline"
                        onClick={clearFilters}
                    >
                        Xóa bộ lọc
                    </button>
                )}
            </div>
            {orders.isPending ? (
                <div className="space-y-3" aria-label="Đang tải đơn hàng">
                    <div className="hidden space-y-2 lg:block">
                        {Array.from({ length: 5 }, (_, index) => (
                            <div key={index} className="h-16 animate-pulse rounded-lg bg-muted" />
                        ))}
                    </div>
                    <div className="space-y-2 lg:hidden">
                        {Array.from({ length: 4 }, (_, index) => (
                            <div key={index} className="h-40 animate-pulse rounded-xl bg-muted" />
                        ))}
                    </div>
                </div>
            ) : orders.isError ? (
                <ErrorState
                    message={errorMessage(orders.error)}
                    retry={() => void orders.refetch()}
                />
            ) : orders.data.data.length === 0 ? (
                hasFilters ? (
                    <div className="rounded-xl border bg-card p-8 text-center text-sm">
                        <p>Không tìm thấy đơn hàng phù hợp.</p>
                        <button
                            type="button"
                            className="mt-3 text-primary underline"
                            onClick={clearFilters}
                        >
                            Xóa bộ lọc
                        </button>
                    </div>
                ) : (
                    <div className="space-y-3 rounded-xl border bg-card p-8 text-center text-sm">
                        <h2 className="font-semibold">Chưa có đơn hàng</h2>
                        <p>Bạn chưa tạo đơn hàng nào.</p>
                        <Link
                            to="/dealer/quick-order"
                            search={{ sku: "", reorder: 0 }}
                            className="inline-block rounded-md bg-primary px-4 py-2 text-primary-foreground"
                        >
                            Đặt hàng nhanh
                        </Link>
                    </div>
                )
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-xl border bg-card 2xl:block">
                        <table className="w-full min-w-[1120px] table-fixed text-left text-sm">
                            <thead className="border-b bg-muted/30 text-[13px] font-semibold text-muted-foreground">
                                <tr>
                                    <th className="w-[15%] px-3 py-3">Đơn hàng</th>
                                    <th className="w-[18%] px-3 py-3">Người nhận</th>
                                    <th className="w-[10%] px-3 py-3">Ngày đặt</th>
                                    <th className="w-[12%] px-3 py-3">Sản phẩm</th>
                                    <th className="w-[13%] px-3 py-3">Thanh toán</th>
                                    <th className="w-[15%] px-3 py-3">Trạng thái</th>
                                    <th className="w-[14%] px-3 py-3 text-right">Tổng tiền</th>
                                    <th className="w-14 px-2 py-3.5">
                                        <span className="sr-only">Thao tác</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.data.data.map((order) => (
                                    <OrderRow key={order.id} order={order} layout="table" />
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="grid gap-3 2xl:hidden">
                        {orders.data.data.map((order) => (
                            <OrderRow key={order.id} order={order} layout="card" />
                        ))}
                    </div>
                    <p className="text-center text-sm text-muted-foreground">
                        Hiển thị {orders.data.meta.from}–{orders.data.meta.to} /{" "}
                        {orders.data.meta.total} đơn
                    </p>
                    <Pagination
                        current={orders.data.meta.current_page}
                        last={orders.data.meta.last_page}
                        onPage={setPage}
                    />
                </>
            )}
        </section>
    );
}
