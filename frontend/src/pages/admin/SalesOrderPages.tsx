import { useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate } from "@tanstack/react-router";
import { toast } from "sonner";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { ApiError, errorMessage, firstFieldErrors } from "@/services/api";
import { inventoryApi } from "@/services/inventoryApi";
import { salesOrderApi, salesOrderKeys } from "@/services/salesOrderApi";
import type { SalesOrderDraftInput } from "@/types/salesOrder";
import {
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "./ProductAdminShared";

const money = (value: string) => `${new Intl.NumberFormat("vi-VN").format(Number(value))} ₫`;
const statusText: Record<string, string> = {
    draft: "Nháp",
    confirmed: "Đã xác nhận",
    processing: "Đang xuất hàng",
    completed: "Hoàn thành",
    cancelled: "Đã hủy",
    unfulfilled: "Chưa giữ hàng",
    reserved: "Đã giữ hàng",
    partially_fulfilled: "Đã xuất một phần",
    fulfilled: "Đã xuất đủ",
    unpaid: "Chưa thanh toán",
};

function Field({
    label,
    name,
    error,
    children,
}: {
    label: string;
    name: string;
    error?: string | undefined;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-1" data-field={name}>
            <label htmlFor={name} className="block text-sm font-medium">
                {label}
            </label>
            {children}
            {error && <p className="text-xs text-red-700">{error}</p>}
        </div>
    );
}

export function SalesOrderListPage() {
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("");
    const [page, setPage] = useState(1);
    const filters = { search, order_status: status, page };
    const query = useQuery({
        queryKey: salesOrderKeys.list(filters),
        queryFn: () => salesOrderApi.list(filters),
    });

    return (
        <ProductAdminGuard>
            <div className="space-y-6">
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="label-luxury">Sales Order Core</p>
                        <h1 className="mt-2 text-3xl text-primary">Đơn bán hàng</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Đơn Retail do Admin tạo. Bản nháp chưa giữ tồn kho.
                        </p>
                    </div>
                    <Link to="/admin/sales-orders/new" className={buttonClass}>
                        + Tạo đơn Retail
                    </Link>
                </header>
                <section className="rounded-xl border bg-card p-5">
                    <div className="grid gap-3 sm:grid-cols-2">
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
                            {["draft", "confirmed", "processing", "completed", "cancelled"].map(
                                (value) => (
                                    <option key={value} value={value}>
                                        {statusText[value]}
                                    </option>
                                ),
                            )}
                        </select>
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
                                <table className="w-full min-w-[780px] text-left text-sm">
                                    <thead className="border-b text-muted-foreground">
                                        <tr>
                                            <th className="py-2">Đơn</th>
                                            <th>Người mua / nhận</th>
                                            <th>Kho</th>
                                            <th>Mặt hàng</th>
                                            <th>Tổng</th>
                                            <th>Trạng thái</th>
                                            <th>Thanh toán / xuất hàng</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {query.data.data.map((order) => (
                                            <tr key={order.id} className="border-b last:border-0">
                                                <td className="py-3">
                                                    <Link
                                                        to="/admin/sales-orders/$id"
                                                        params={{ id: String(order.id) }}
                                                        className="font-semibold text-primary underline"
                                                    >
                                                        {order.order_code}
                                                    </Link>
                                                    <p className="text-xs text-muted-foreground">
                                                        {new Date(order.created_at).toLocaleString(
                                                            "vi-VN",
                                                        )}
                                                    </p>
                                                </td>
                                                <td>
                                                    {order.buyer?.name}
                                                    <p>{order.recipient_name}</p>
                                                </td>
                                                <td>{order.warehouse?.code}</td>
                                                <td>{order.items_count}</td>
                                                <td className="font-medium">
                                                    {money(order.grand_total)}
                                                </td>
                                                <td>{statusText[order.order_status]}</td>
                                                <td>
                                                    {statusText[order.payment_status] ??
                                                        order.payment_status}{" "}
                                                    / {statusText[order.fulfillment_status]}
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

const emptyDraft: Omit<SalesOrderDraftInput, "operation_key"> = {
    sales_channel: "retail",
    buyer_user_id: 0,
    warehouse_id: 0,
    currency: "VND",
    recipient_name: "",
    recipient_phone: "",
    recipient_email: null,
    shipping_address_line1: "",
    shipping_address_line2: null,
    shipping_city: "",
    shipping_province: "",
    shipping_country: "VN",
    shipping_postal_code: null,
    delivery_note: null,
    items: [{ sku: "", quantity: "1" }],
};

export function SalesOrderCreatePage() {
    const navigate = useNavigate();
    const [form, setForm] = useState(emptyDraft);
    const [buyerSearch, setBuyerSearch] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [notice, setNotice] = useState("");
    const [busy, setBusy] = useState(false);
    const operationKey = useRef(globalThis.crypto.randomUUID());
    const warehouses = useQuery({
        queryKey: ["sales-order-warehouses"],
        queryFn: () => inventoryApi.warehouses({ status: "active", per_page: 100 }),
    });
    const buyers = useQuery({
        queryKey: salesOrderKeys.buyers(buyerSearch),
        queryFn: () => salesOrderApi.buyers(buyerSearch),
    });
    const update = (patch: Partial<typeof form>) => {
        setForm((current) => ({ ...current, ...patch }));
        setErrors({});
        setNotice("");
        operationKey.current = globalThis.crypto.randomUUID();
    };
    const submit = async () => {
        if (busy) return;
        setBusy(true);
        setNotice("");
        try {
            const response = await salesOrderApi.create({
                ...form,
                operation_key: operationKey.current,
                items: form.items.map((item) => ({
                    sku: item.sku.trim().toUpperCase(),
                    quantity: item.quantity,
                })),
            });
            toast.success("Đã tạo bản nháp đơn Retail.");
            await navigate({
                to: "/admin/sales-orders/$id",
                params: { id: String(response.data.id) },
            });
        } catch (reason) {
            setErrors(firstFieldErrors(reason));
            setNotice(errorMessage(reason));
        } finally {
            setBusy(false);
        }
    };

    return (
        <ProductAdminGuard>
            <div className="mx-auto max-w-5xl space-y-6">
                <header>
                    <Link to="/admin/sales-orders" className={secondaryButtonClass}>
                        ← Đơn hàng
                    </Link>
                    <h1 className="mt-6 text-3xl text-primary">Tạo bản nháp Retail</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Giá được xác định từ bảng giá Retail trên máy chủ. Bản nháp chưa giữ hàng.
                    </p>
                </header>
                {notice && (
                    <p
                        role="alert"
                        className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700"
                    >
                        {notice}
                    </p>
                )}
                <section className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2">
                    <h2 className="text-xl text-primary sm:col-span-2">Người mua và kho</h2>
                    <Field
                        name="buyer_search"
                        label="Tìm khách hàng"
                        error={errors["buyer_user_id"]}
                    >
                        <input
                            id="buyer_search"
                            className={fieldClass}
                            value={buyerSearch}
                            onChange={(event) => setBuyerSearch(event.target.value)}
                            placeholder="Tên hoặc email"
                        />
                    </Field>
                    <Field name="buyer_user_id" label="Người mua *" error={errors["buyer_user_id"]}>
                        <select
                            id="buyer_user_id"
                            className={fieldClass}
                            value={form.buyer_user_id || ""}
                            onChange={(event) =>
                                update({ buyer_user_id: Number(event.target.value) })
                            }
                        >
                            <option value="">Chọn khách hàng</option>
                            {buyers.data?.data.map((buyer) => (
                                <option key={buyer.id} value={buyer.id}>
                                    {buyer.name} · {buyer.email}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field
                        name="warehouse_id"
                        label="Kho xuất hàng *"
                        error={errors["warehouse_id"]}
                    >
                        <select
                            id="warehouse_id"
                            className={fieldClass}
                            value={form.warehouse_id || ""}
                            onChange={(event) =>
                                update({ warehouse_id: Number(event.target.value) })
                            }
                        >
                            <option value="">Chọn kho</option>
                            {warehouses.data?.data.map((warehouse) => (
                                <option key={warehouse.id} value={warehouse.id}>
                                    {warehouse.code} · {warehouse.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field name="currency" label="Tiền tệ" error={errors["currency"]}>
                        <input id="currency" className={fieldClass} value="VND" readOnly />
                    </Field>
                </section>
                <section className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2">
                    <h2 className="text-xl text-primary sm:col-span-2">
                        Người nhận và địa chỉ giao hàng
                    </h2>
                    {(
                        [
                            ["recipient_name", "Tên người nhận *"],
                            ["recipient_phone", "Số điện thoại *"],
                            ["recipient_email", "Email"],
                            ["shipping_address_line1", "Địa chỉ dòng 1 *"],
                            ["shipping_address_line2", "Địa chỉ dòng 2"],
                            ["shipping_city", "Thành phố *"],
                            ["shipping_province", "Tỉnh / thành *"],
                            ["shipping_country", "Quốc gia *"],
                            ["shipping_postal_code", "Mã bưu chính"],
                            ["delivery_note", "Ghi chú giao hàng"],
                        ] as const
                    ).map(([name, label]) => (
                        <Field key={name} name={name} label={label} error={errors[name]}>
                            <input
                                id={name}
                                className={fieldClass}
                                value={form[name] ?? ""}
                                onChange={(event) => update({ [name]: event.target.value })}
                            />
                        </Field>
                    ))}
                </section>
                <section className="space-y-4 rounded-xl border bg-card p-5">
                    <h2 className="text-xl text-primary">SKU và số lượng</h2>
                    {form.items.map((item, index) => (
                        <div key={index} className="grid gap-3 sm:grid-cols-[2fr_1fr_auto]">
                            <Field
                                name={`items.${index}.sku`}
                                label="SKU *"
                                error={errors[`items.${index}.sku`]}
                            >
                                <input
                                    id={`items.${index}.sku`}
                                    className={fieldClass}
                                    value={item.sku}
                                    onChange={(event) =>
                                        update({
                                            items: form.items.map((row, position) =>
                                                position === index
                                                    ? { ...row, sku: event.target.value }
                                                    : row,
                                            ),
                                        })
                                    }
                                />
                            </Field>
                            <Field
                                name={`items.${index}.quantity`}
                                label="Số lượng *"
                                error={errors[`items.${index}.quantity`]}
                            >
                                <input
                                    id={`items.${index}.quantity`}
                                    type="number"
                                    min="0.001"
                                    step="0.001"
                                    className={fieldClass}
                                    value={item.quantity}
                                    onChange={(event) =>
                                        update({
                                            items: form.items.map((row, position) =>
                                                position === index
                                                    ? { ...row, quantity: event.target.value }
                                                    : row,
                                            ),
                                        })
                                    }
                                />
                            </Field>
                            <button
                                type="button"
                                className={secondaryButtonClass}
                                disabled={form.items.length === 1}
                                onClick={() =>
                                    update({
                                        items: form.items.filter(
                                            (_, position) => position !== index,
                                        ),
                                    })
                                }
                            >
                                Xóa
                            </button>
                        </div>
                    ))}
                    <button
                        type="button"
                        className={secondaryButtonClass}
                        onClick={() =>
                            update({ items: [...form.items, { sku: "", quantity: "1" }] })
                        }
                    >
                        + Thêm SKU
                    </button>
                    {errors["items"] && <p className="text-xs text-red-700">{errors["items"]}</p>}
                </section>
                <div className="flex justify-end">
                    <button
                        type="button"
                        className={buttonClass}
                        disabled={busy}
                        onClick={() => void submit()}
                    >
                        {busy ? "Đang tạo..." : "Tạo bản nháp"}
                    </button>
                </div>
            </div>
        </ProductAdminGuard>
    );
}

export function SalesOrderDetailPage({ id }: { id: number }) {
    const client = useQueryClient();
    const query = useQuery({
        queryKey: salesOrderKeys.detail(id),
        queryFn: () => salesOrderApi.detail(id),
    });
    const [busy, setBusy] = useState("");
    const [notice, setNotice] = useState("");
    const [stalePrice, setStalePrice] = useState(false);
    const [quantities, setQuantities] = useState<Record<number, string>>({});
    const retryKeys = useRef<Record<string, string>>({});
    const action = async (
        name: string,
        payload: string,
        call: (key: string) => Promise<unknown>,
    ) => {
        if (busy) return;
        const signature = `${name}:${payload}`;
        retryKeys.current[signature] ??= globalThis.crypto.randomUUID();
        setBusy(name);
        setNotice("");
        try {
            await call(retryKeys.current[signature]!);
            delete retryKeys.current[signature];
            setStalePrice(false);
            toast.success("Đã cập nhật đơn hàng.");
            await client.invalidateQueries({ queryKey: salesOrderKeys.detail(id) });
            await client.invalidateQueries({ queryKey: ["sales-orders"] });
        } catch (reason) {
            if (reason instanceof ApiError && reason.code === "ORDER_PRICE_CHANGED")
                setStalePrice(true);
            if (reason instanceof ApiError && reason.code === "INSUFFICIENT_STOCK") {
                setNotice(
                    `Thiếu tồn kho SKU ${reason.details.sku ?? ""}: cần ${reason.details.requested ?? ""}, còn ${reason.details.available ?? ""}.`,
                );
            } else {
                setNotice(errorMessage(reason));
            }
        } finally {
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
    const cancel = () => {
        const reason = window.prompt("Lý do hủy đơn:");
        if (!reason?.trim() || !window.confirm("Xác nhận hủy đơn?")) return;
        void action("cancel", reason.trim(), (key) => salesOrderApi.cancel(id, key, reason.trim()));
    };
    const fulfillItems = order.items
        .map((item) => ({ item_id: item.id, quantity: quantities[item.id] ?? "" }))
        .filter((item) => item.quantity && Number(item.quantity) > 0);

    return (
        <ProductAdminGuard>
            <div className="space-y-6">
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <Link to="/admin/sales-orders" className={secondaryButtonClass}>
                            ← Đơn hàng
                        </Link>
                        <h1 className="mt-6 text-3xl text-primary">{order.order_code}</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {statusText[order.order_status]} ·{" "}
                            {statusText[order.fulfillment_status]} ·{" "}
                            {statusText[order.payment_status] ?? order.payment_status}
                        </p>
                    </div>
                    <p className="text-2xl font-semibold">{money(order.grand_total)}</p>
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
                <div className="grid gap-5 lg:grid-cols-2">
                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="text-xl text-primary">Người mua và người nhận</h2>
                        <dl className="mt-4 space-y-2 text-sm">
                            <div>
                                Người mua: <strong>{order.buyer.name}</strong> ({order.buyer.email})
                            </div>
                            <div>
                                Người nhận: <strong>{order.recipient_name}</strong> ·{" "}
                                {order.recipient_phone}
                            </div>
                            <div>Email nhận: {order.recipient_email || "—"}</div>
                            <div>
                                Địa chỉ: {order.shipping_address_line1},{" "}
                                {order.shipping_address_line2
                                    ? `${order.shipping_address_line2}, `
                                    : ""}
                                {order.shipping_city}, {order.shipping_province},{" "}
                                {order.shipping_country}
                            </div>
                            <div>Ghi chú: {order.delivery_note || "—"}</div>
                        </dl>
                    </section>
                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="text-xl text-primary">Kho và tổng tiền</h2>
                        <dl className="mt-4 space-y-2 text-sm">
                            <div>
                                Kho: <strong>{order.warehouse.code}</strong> ·{" "}
                                {order.warehouse.name}
                            </div>
                            <div>Tiền hàng: {money(order.subtotal)}</div>
                            <div>
                                Giảm giá / thuế / vận chuyển: {money(order.discount_total)} /{" "}
                                {money(order.tax_total)} / {money(order.shipping_total)}
                            </div>
                            <div className="font-semibold">Tổng: {money(order.grand_total)}</div>
                        </dl>
                    </section>
                </div>
                <section className="rounded-xl border bg-card p-5">
                    <h2 className="text-xl text-primary">Mặt hàng và giá đã chụp</h2>
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[660px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="py-2">SKU</th>
                                    <th>Sản phẩm</th>
                                    <th>Số lượng</th>
                                    <th>Đơn giá Retail</th>
                                    <th>Thành tiền</th>
                                    <th>Đã xuất</th>
                                </tr>
                            </thead>
                            <tbody>
                                {order.items.map((item) => (
                                    <tr key={item.id} className="border-b last:border-0">
                                        <td className="py-3 font-mono">{item.sku_snapshot}</td>
                                        <td>
                                            {item.product_name_snapshot} ·{" "}
                                            {item.variant_name_snapshot}
                                        </td>
                                        <td>
                                            {item.quantity} {item.unit_name_snapshot}
                                        </td>
                                        <td>{money(item.unit_price_snapshot)}</td>
                                        <td>{money(item.line_total)}</td>
                                        <td>{item.reservation?.consumed_quantity ?? "0"}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
                {order.order_status === "draft" && (
                    <section className="flex flex-wrap gap-3 rounded-xl border bg-card p-5">
                        <button
                            type="button"
                            className={secondaryButtonClass}
                            disabled={Boolean(busy)}
                            onClick={() =>
                                void action("reprice", "", (key) => salesOrderApi.reprice(id, key))
                            }
                        >
                            {busy === "reprice" ? "Đang cập nhật..." : "Cập nhật giá"}
                        </button>
                        <button
                            type="button"
                            className={buttonClass}
                            disabled={Boolean(busy)}
                            onClick={() => {
                                if (window.confirm("Xác nhận đơn và giữ tồn kho?"))
                                    void action("confirm", "", (key) =>
                                        salesOrderApi.confirm(id, key),
                                    );
                            }}
                        >
                            {busy === "confirm" ? "Đang xác nhận..." : "Xác nhận & giữ hàng"}
                        </button>
                        <button
                            type="button"
                            className={secondaryButtonClass}
                            disabled={Boolean(busy)}
                            onClick={cancel}
                        >
                            Hủy đơn
                        </button>
                    </section>
                )}
                {(order.order_status === "confirmed" || order.order_status === "processing") && (
                    <section className="space-y-4 rounded-xl border bg-card p-5">
                        <h2 className="text-xl text-primary">Xuất hàng từ phần đã giữ</h2>
                        {order.items.map((item) => {
                            const reservation = item.reservation;
                            const remaining = reservation
                                ? Number(reservation.original_quantity) -
                                  Number(reservation.consumed_quantity) -
                                  Number(reservation.released_quantity)
                                : 0;
                            return (
                                <Field
                                    key={item.id}
                                    name={`quantity-${item.id}`}
                                    label={`${item.sku_snapshot} · còn giữ ${remaining}`}
                                >
                                    <input
                                        id={`quantity-${item.id}`}
                                        type="number"
                                        min="0"
                                        max={remaining}
                                        step="0.001"
                                        className={fieldClass}
                                        value={quantities[item.id] ?? ""}
                                        onChange={(event) =>
                                            setQuantities((current) => ({
                                                ...current,
                                                [item.id]: event.target.value,
                                            }))
                                        }
                                    />
                                </Field>
                            );
                        })}
                        <div className="flex flex-wrap gap-3">
                            <button
                                type="button"
                                className={buttonClass}
                                disabled={Boolean(busy) || fulfillItems.length === 0}
                                onClick={() => {
                                    if (window.confirm("Xác nhận xuất hàng và trừ tồn kho?"))
                                        void action(
                                            "fulfill",
                                            JSON.stringify(fulfillItems),
                                            (key) => salesOrderApi.fulfill(id, key, fulfillItems),
                                        );
                                }}
                            >
                                {busy === "fulfill" ? "Đang xuất..." : "Xác nhận xuất hàng"}
                            </button>
                            {order.order_status === "confirmed" && (
                                <button
                                    type="button"
                                    className={secondaryButtonClass}
                                    disabled={Boolean(busy)}
                                    onClick={cancel}
                                >
                                    Hủy & thả tồn giữ
                                </button>
                            )}
                        </div>
                    </section>
                )}
            </div>
        </ProductAdminGuard>
    );
}
