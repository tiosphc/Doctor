import { useEffect, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate } from "@tanstack/react-router";
import { toast } from "sonner";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from "@/components/ui/sheet";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { formatProductQuantity, isPositiveProductQuantity } from "@/lib/productQuantity";
import { inventoryApi } from "@/services/inventoryApi";
import { procurementApi } from "@/services/procurementApi";
import { productApi } from "@/services/productApi";
import type { PurchaseOrder, PurchaseOrderInput } from "@/types/procurement";
import {
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "./ProductAdminShared";

const money = (value: string | number) =>
    new Intl.NumberFormat("vi-VN").format(Number(value)) + " ₫";
const orderStatus: Record<PurchaseOrder["status"], string> = {
    draft: "Nháp",
    ordered: "Đã đặt",
    partially_received: "Nhận một phần",
    received: "Đã nhận đủ",
    cancelled: "Đã hủy",
};
const statusStyle: Record<PurchaseOrder["status"], string> = {
    draft: "bg-slate-100 text-slate-700",
    ordered: "bg-blue-50 text-blue-700",
    partially_received: "bg-amber-50 text-amber-800",
    received: "bg-emerald-50 text-emerald-700",
    cancelled: "bg-red-50 text-red-700",
};
const statusBadge = (status: PurchaseOrder["status"]) => (
    <span
        className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${statusStyle[status]}`}
    >
        {orderStatus[status]}
    </span>
);
type DraftLine = { product_variant_id: string; quantity: string; unit_price: string };
const emptyLine = (): DraftLine => ({ product_variant_id: "", quantity: "", unit_price: "" });

export function PurchaseOrdersPage() {
    const [search, setSearch] = useState("");
    const [supplierId, setSupplierId] = useState("");
    const [warehouseId, setWarehouseId] = useState("");
    const [status, setStatus] = useState("");
    const [page, setPage] = useState(1);
    const filters = {
        search,
        ...(supplierId ? { supplier_id: Number(supplierId) } : {}),
        ...(warehouseId ? { warehouse_id: Number(warehouseId) } : {}),
        ...(status ? { status } : {}),
        page,
    };
    const orders = useQuery({
        queryKey: ["purchase-orders", filters],
        queryFn: () => procurementApi.orders(filters),
    });
    const suppliers = useQuery({
        queryKey: ["suppliers", "purchase-filter"],
        queryFn: () => procurementApi.suppliers({ per_page: 100 }),
    });
    const warehouses = useQuery({
        queryKey: ["warehouses", "purchase-filter"],
        queryFn: () => inventoryApi.warehouses({ per_page: 100 }),
    });
    return (
        <ProductAdminGuard>
            <div className="space-y-6">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="label-luxury">Procurement</p>
                        <h1 className="mt-2 text-3xl text-primary">Đơn mua hàng</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Tạo PO không tăng tồn; tồn kho chỉ cập nhật khi xác nhận nhận hàng.
                        </p>
                    </div>
                    <Link to="/admin/purchase-orders/new" className={buttonClass}>
                        + Tạo đơn mua
                    </Link>
                </header>
                <section className="space-y-4 rounded-xl border bg-card p-4 sm:p-5">
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <label className="text-sm">
                            Tìm mã PO
                            <input
                                className={fieldClass}
                                value={search}
                                onChange={(event) => {
                                    setSearch(event.target.value);
                                    setPage(1);
                                }}
                                placeholder="PO-…"
                            />
                        </label>
                        <label className="text-sm">
                            Nhà cung cấp
                            <select
                                className={fieldClass}
                                value={supplierId}
                                onChange={(event) => {
                                    setSupplierId(event.target.value);
                                    setPage(1);
                                }}
                            >
                                <option value="">Tất cả</option>
                                {suppliers.data?.data.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm">
                            Kho nhận
                            <select
                                className={fieldClass}
                                value={warehouseId}
                                onChange={(event) => {
                                    setWarehouseId(event.target.value);
                                    setPage(1);
                                }}
                            >
                                <option value="">Tất cả</option>
                                {warehouses.data?.data.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm">
                            Trạng thái
                            <select
                                className={fieldClass}
                                value={status}
                                onChange={(event) => {
                                    setStatus(event.target.value);
                                    setPage(1);
                                }}
                            >
                                <option value="">Tất cả</option>
                                {Object.entries(orderStatus).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    {orders.isPending ? (
                        <LoadingState />
                    ) : orders.isError ? (
                        <ErrorState
                            message={errorMessage(orders.error)}
                            retry={() => void orders.refetch()}
                        />
                    ) : orders.data.data.length === 0 ? (
                        <EmptyState message="Chưa có đơn mua phù hợp." />
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[650px] text-left text-sm">
                                    <thead className="border-b text-muted-foreground">
                                        <tr>
                                            <th className="p-3">Mã PO / Ngày tạo</th>
                                            <th className="p-3">Nhà cung cấp</th>
                                            <th className="p-3">Kho nhận</th>
                                            <th className="p-3">Tổng tiền</th>
                                            <th className="p-3">Trạng thái</th>
                                            <th className="p-3">Thanh toán</th>
                                            <th className="p-3">Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {orders.data.data.map((order) => (
                                            <tr key={order.id} className="border-b">
                                                <td className="p-3">
                                                    <Link
                                                        to="/admin/purchase-orders/$id"
                                                        params={{ id: String(order.id) }}
                                                        className="font-semibold text-primary underline"
                                                    >
                                                        {order.code}
                                                    </Link>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {new Date(order.created_at).toLocaleString(
                                                            "vi-VN",
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="p-3">{order.supplier.name}</td>
                                                <td className="p-3">{order.warehouse.name}</td>
                                                <td className="p-3 tabular-nums">
                                                    {money(order.total_amount)}
                                                </td>
                                                <td className="p-3">{statusBadge(order.status)}</td>
                                                <td className="p-3">
                                                    <span
                                                        className={`rounded-full px-2.5 py-1 text-xs font-medium ${order.payment_status === "paid" ? "bg-emerald-50 text-emerald-700" : order.payment_status === "partially_paid" ? "bg-amber-50 text-amber-800" : "bg-slate-100 text-slate-700"}`}
                                                    >
                                                        {order.payment_status === "paid"
                                                            ? "Đã trả"
                                                            : order.payment_status ===
                                                                "partially_paid"
                                                              ? "Trả một phần"
                                                              : "Chưa trả"}
                                                    </span>
                                                </td>
                                                <td className="p-3">
                                                    <Link
                                                        to="/admin/purchase-orders/$id"
                                                        params={{ id: String(order.id) }}
                                                        className="text-primary underline"
                                                    >
                                                        Xem
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <Pagination
                                current={orders.data.current_page}
                                last={orders.data.last_page}
                                onPage={setPage}
                            />
                        </>
                    )}
                </section>
            </div>
        </ProductAdminGuard>
    );
}

export function PurchaseOrderFormPage({ id }: { id?: number }) {
    const navigate = useNavigate();
    const client = useQueryClient();
    const [supplierId, setSupplierId] = useState("");
    const [warehouseId, setWarehouseId] = useState("");
    const [note, setNote] = useState("");
    const [expectedDeliveryDate, setExpectedDeliveryDate] = useState("");
    const [supplierOrderReference, setSupplierOrderReference] = useState("");
    const [lines, setLines] = useState<DraftLine[]>([emptyLine()]);
    const [productSearch, setProductSearch] = useState("");
    const [busy, setBusy] = useState(false);
    const [formError, setFormError] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const submitting = useRef(false);
    const hydratedId = useRef<number | null>(null);
    const detail = useQuery({
        queryKey: ["purchase-order", id],
        queryFn: () => procurementApi.order(id!),
        enabled: Boolean(id),
    });
    const suppliers = useQuery({
        queryKey: ["suppliers", "purchase-form"],
        queryFn: () => procurementApi.suppliers({ status: "active", per_page: 100 }),
    });
    const warehouses = useQuery({
        queryKey: ["warehouses", "purchase-form"],
        queryFn: () => inventoryApi.warehouses({ status: "active", per_page: 100 }),
    });
    const products = useQuery({
        queryKey: ["procurement-products", productSearch],
        queryFn: () => productApi.adminProducts({ search: productSearch, page: 1 }),
    });
    const variants =
        products.data?.data.flatMap((product) =>
            product.status === "inactive"
                ? []
                : product.variants
                      .filter((variant) => variant.track_inventory && variant.status === "active")
                      .map((variant) => ({ ...variant, productName: product.name })),
        ) ?? [];
    const existingOrder = detail.data?.data;
    useEffect(() => {
        if (existingOrder && hydratedId.current !== existingOrder.id) {
            hydratedId.current = existingOrder.id;
            const order = existingOrder;
            setSupplierId(String(order.supplier_id));
            setWarehouseId(String(order.warehouse_id));
            setNote(order.note ?? "");
            setExpectedDeliveryDate(order.expected_delivery_date ?? "");
            setSupplierOrderReference(order.supplier_order_reference ?? "");
            setLines(
                order.items.map((item) => ({
                    product_variant_id: String(item.product_variant_id),
                    quantity: formatProductQuantity(item.ordered_quantity),
                    unit_price: item.unit_price,
                })),
            );
        }
    }, [existingOrder]);
    const updateLine = (index: number, patch: Partial<DraftLine>) =>
        setLines((current) =>
            current.map((line, at) => (at === index ? { ...line, ...patch } : line)),
        );
    const total = lines.reduce(
        (sum, line) => sum + (Number(line.quantity) || 0) * (Number(line.unit_price) || 0),
        0,
    );
    const save = async (issue: boolean) => {
        if (submitting.current) return;
        setFormError("");
        setErrors({});
        if (
            !supplierId ||
            !warehouseId ||
            !lines.length ||
            lines.some(
                (line) =>
                    !line.product_variant_id ||
                    !isPositiveProductQuantity(line.quantity) ||
                    !/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(line.unit_price),
            ) ||
            new Set(lines.map((line) => line.product_variant_id)).size !== lines.length
        ) {
            setFormError(
                "Chọn nhà cung cấp, kho và SKU không trùng; số lượng phải là số nguyên dương và giá mua hợp lệ.",
            );
            return;
        }
        submitting.current = true;
        setBusy(true);
        const body: PurchaseOrderInput = {
            supplier_id: Number(supplierId),
            warehouse_id: Number(warehouseId),
            note,
            expected_delivery_date: expectedDeliveryDate || null,
            supplier_order_reference: supplierOrderReference.trim() || null,
            items: lines.map((line) => ({
                product_variant_id: Number(line.product_variant_id),
                quantity: line.quantity,
                unit_price: line.unit_price,
            })),
        };
        let savedId: number | null = id ?? null;
        let savedSuccessfully = false;
        try {
            const saved = id
                ? await procurementApi.updateOrder(id, body)
                : await procurementApi.createOrder(body);
            savedId = saved.data.id;
            savedSuccessfully = true;
            await client.invalidateQueries({ queryKey: ["purchase-orders"] });
            if (issue) await procurementApi.issueOrder(savedId);
            toast.success(
                id
                    ? issue
                        ? "Đã cập nhật và phát hành đơn mua hàng."
                        : "Cập nhật đơn mua hàng thành công."
                    : issue
                      ? "Tạo đơn mua hàng thành công."
                      : "Đã lưu bản nháp đơn mua hàng.",
            );
            await navigate({ to: "/admin/purchase-orders/$id", params: { id: String(savedId) } });
        } catch (error) {
            setFormError(errorMessage(error));
            setErrors(firstFieldErrors(error));
            if (savedSuccessfully && savedId && issue) {
                toast.error("PO đã lưu nháp nhưng chưa phát hành. Kiểm tra chi tiết đơn.");
                await navigate({
                    to: "/admin/purchase-orders/$id",
                    params: { id: String(savedId) },
                });
            }
        } finally {
            submitting.current = false;
            setBusy(false);
        }
    };
    return (
        <ProductAdminGuard>
            <div className="mx-auto max-w-6xl space-y-6">
                {id ? (
                    <Link
                        to="/admin/purchase-orders/$id"
                        params={{ id: String(id) }}
                        className="text-sm text-primary underline"
                    >
                        ← Đơn mua hàng
                    </Link>
                ) : (
                    <Link to="/admin/purchase-orders" className="text-sm text-primary underline">
                        ← Đơn mua hàng
                    </Link>
                )}
                <header>
                    <p className="label-luxury">Procurement</p>
                    <h1 className="mt-2 text-3xl text-primary">
                        {id ? "Sửa PO nháp" : "Tạo đơn mua hàng"}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Lưu nháp hoặc phát hành đơn mua. Chỉ khi nhận hàng, tồn kho mới tăng.
                    </p>
                </header>
                {id &&
                    (detail.isPending ? (
                        <LoadingState />
                    ) : detail.isError ? (
                        <ErrorState
                            message={errorMessage(detail.error)}
                            retry={() => void detail.refetch()}
                        />
                    ) : existingOrder?.status !== "draft" ? (
                        <ErrorState message="Chỉ PO nháp mới được sửa." />
                    ) : null)}
                {(!id || existingOrder?.status === "draft") && (
                    <div className="space-y-5">
                        <section className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2">
                            <label className="text-sm">
                                Nhà cung cấp *
                                <select
                                    className={fieldClass}
                                    value={supplierId}
                                    onChange={(event) => setSupplierId(event.target.value)}
                                >
                                    <option value="">Chọn nhà cung cấp</option>
                                    {existingOrder &&
                                        !suppliers.data?.data.some(
                                            (item) => item.id === existingOrder.supplier_id,
                                        ) && (
                                            <option value={existingOrder.supplier_id}>
                                                {existingOrder.supplier.name}
                                            </option>
                                        )}
                                    {suppliers.data?.data.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.code} · {item.name}
                                        </option>
                                    ))}
                                </select>
                                {errors["supplier_id"] && (
                                    <span className="text-red-700">{errors["supplier_id"]}</span>
                                )}
                            </label>
                            <label className="text-sm">
                                Kho nhận *
                                <select
                                    className={fieldClass}
                                    value={warehouseId}
                                    onChange={(event) => setWarehouseId(event.target.value)}
                                >
                                    <option value="">Chọn kho</option>
                                    {existingOrder &&
                                        !warehouses.data?.data.some(
                                            (item) => item.id === existingOrder.warehouse_id,
                                        ) && (
                                            <option value={existingOrder.warehouse_id}>
                                                {existingOrder.warehouse.name}
                                            </option>
                                        )}
                                    {warehouses.data?.data.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.code} · {item.name}
                                        </option>
                                    ))}
                                </select>
                                {errors["warehouse_id"] && (
                                    <span className="text-red-700">{errors["warehouse_id"]}</span>
                                )}
                            </label>
                            <p className="text-sm">
                                Ngày đặt
                                <br />
                                <strong>
                                    {new Date(
                                        existingOrder?.created_at ?? Date.now(),
                                    ).toLocaleDateString("vi-VN")}
                                </strong>
                                <span className="block text-xs text-muted-foreground">
                                    Ghi nhận tự động khi lưu PO.
                                </span>
                            </p>
                            <label className="text-sm">
                                Ngày giao dự kiến
                                <input
                                    type="date"
                                    className={fieldClass}
                                    value={expectedDeliveryDate}
                                    onChange={(event) =>
                                        setExpectedDeliveryDate(event.target.value)
                                    }
                                />
                            </label>
                            <label className="text-sm">
                                Mã tham chiếu nhà cung cấp
                                <input
                                    className={fieldClass}
                                    value={supplierOrderReference}
                                    onChange={(event) =>
                                        setSupplierOrderReference(event.target.value)
                                    }
                                />
                            </label>
                            <label className="text-sm">
                                Ghi chú
                                <textarea
                                    className={fieldClass}
                                    value={note}
                                    onChange={(event) => setNote(event.target.value)}
                                    rows={2}
                                />
                            </label>
                        </section>
                        <section className="space-y-4 rounded-xl border bg-card p-5">
                            <div className="flex flex-wrap items-end justify-between gap-3">
                                <div>
                                    <h2 className="text-xl text-primary">Danh sách sản phẩm</h2>
                                    <p className="text-sm text-muted-foreground">
                                        Mỗi SKU chỉ xuất hiện một lần trong đơn.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    className={secondaryButtonClass}
                                    onClick={() => setLines((current) => [...current, emptyLine()])}
                                >
                                    + Thêm sản phẩm
                                </button>
                            </div>
                            <label className="block max-w-md text-sm">
                                Tìm tên/SKU
                                <input
                                    className={fieldClass}
                                    value={productSearch}
                                    onChange={(event) => setProductSearch(event.target.value)}
                                    placeholder="Tên sản phẩm hoặc SKU"
                                />
                            </label>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[760px] text-left text-sm">
                                    <thead className="border-b text-muted-foreground">
                                        <tr>
                                            <th className="p-2">SKU / Sản phẩm</th>
                                            <th className="p-2">Số lượng</th>
                                            <th className="p-2">Giá mua</th>
                                            <th className="p-2">Thành tiền</th>
                                            <th className="p-2">Xóa</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {lines.map((line, index) => (
                                            <tr key={index} className="border-b align-top">
                                                <td className="p-2">
                                                    <select
                                                        className={fieldClass}
                                                        value={line.product_variant_id}
                                                        onChange={(event) =>
                                                            updateLine(index, {
                                                                product_variant_id:
                                                                    event.target.value,
                                                            })
                                                        }
                                                    >
                                                        <option value="">Chọn SKU</option>
                                                        {line.product_variant_id &&
                                                            !variants.some(
                                                                (variant) =>
                                                                    String(variant.id) ===
                                                                    line.product_variant_id,
                                                            ) && (
                                                                <option
                                                                    value={line.product_variant_id}
                                                                >
                                                                    {existingOrder?.items.find(
                                                                        (item) =>
                                                                            String(
                                                                                item.product_variant_id,
                                                                            ) ===
                                                                            line.product_variant_id,
                                                                    )?.sku_snapshot ??
                                                                        line.product_variant_id}
                                                                </option>
                                                            )}
                                                        {variants.map((variant) => (
                                                            <option
                                                                key={variant.id}
                                                                value={variant.id}
                                                            >
                                                                {variant.sku} ·{" "}
                                                                {variant.productName}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </td>
                                                <td className="p-2">
                                                    <input
                                                        aria-label={`Số lượng dòng ${index + 1}`}
                                                        type="number"
                                                        min="1"
                                                        step="1"
                                                        className={fieldClass}
                                                        value={line.quantity}
                                                        onChange={(event) =>
                                                            updateLine(index, {
                                                                quantity: event.target.value,
                                                            })
                                                        }
                                                    />
                                                </td>
                                                <td className="p-2">
                                                    <input
                                                        aria-label={`Giá mua dòng ${index + 1}`}
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        className={fieldClass}
                                                        value={line.unit_price}
                                                        onChange={(event) =>
                                                            updateLine(index, {
                                                                unit_price: event.target.value,
                                                            })
                                                        }
                                                    />
                                                </td>
                                                <td className="p-2 tabular-nums">
                                                    {money(
                                                        (Number(line.quantity) || 0) *
                                                            (Number(line.unit_price) || 0),
                                                    )}
                                                </td>
                                                <td className="p-2">
                                                    <button
                                                        type="button"
                                                        className="text-red-700 underline"
                                                        onClick={() =>
                                                            setLines((current) =>
                                                                current.filter(
                                                                    (_, at) => at !== index,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        Xóa
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <p className="text-right text-lg font-semibold">
                                Tổng tiền: {money(total)}
                            </p>
                        </section>
                        {(formError || errors["items"]) && (
                            <p
                                role="alert"
                                className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"
                            >
                                {formError || errors["items"]}
                            </p>
                        )}
                        <div className="flex flex-wrap justify-end gap-2">
                            <Link to="/admin/purchase-orders" className={secondaryButtonClass}>
                                Hủy
                            </Link>
                            <button
                                type="button"
                                className={secondaryButtonClass}
                                disabled={busy}
                                onClick={() => void save(false)}
                            >
                                Lưu nháp
                            </button>
                            <button
                                type="button"
                                className={buttonClass}
                                disabled={busy}
                                onClick={() => void save(true)}
                            >
                                {busy ? "Đang lưu…" : id ? "Lưu và phát hành" : "Tạo đơn mua"}
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </ProductAdminGuard>
    );
}

export function PurchaseOrderDetailPage({ id }: { id: number }) {
    const client = useQueryClient();
    const [drawer, setDrawer] = useState<"receipt" | "return" | null>(null);
    const [paymentOpen, setPaymentOpen] = useState(false);
    const [confirmAction, setConfirmAction] = useState<"issue" | "cancel" | null>(null);
    const orderQuery = useQuery({
        queryKey: ["purchase-order", id],
        queryFn: () => procurementApi.order(id),
        enabled: Number.isSafeInteger(id) && id > 0,
    });
    const order = orderQuery.data?.data;
    const refresh = async () => {
        await Promise.all([
            client.invalidateQueries({ queryKey: ["purchase-order", id] }),
            client.invalidateQueries({ queryKey: ["purchase-orders"] }),
            client.invalidateQueries({ queryKey: ["inventory-balances"] }),
            client.invalidateQueries({ queryKey: ["stock-movements"] }),
        ]);
    };
    const action = useMutation({
        mutationFn: (kind: "issue" | "cancel") =>
            kind === "issue" ? procurementApi.issueOrder(id) : procurementApi.cancelOrder(id),
        onSuccess: async (_response, kind) => {
            await refresh();
            toast.success(kind === "issue" ? "Đã phát hành đơn mua hàng." : "Đã hủy đơn mua hàng.");
        },
        onError: (error) => toast.error(errorMessage(error)),
    });
    return (
        <ProductAdminGuard>
            <div className="mx-auto max-w-6xl space-y-6">
                <Link to="/admin/purchase-orders" className="text-sm text-primary underline">
                    ← Đơn mua hàng
                </Link>
                {orderQuery.isPending ? (
                    <LoadingState />
                ) : orderQuery.isError ? (
                    <ErrorState
                        message={errorMessage(orderQuery.error)}
                        retry={() => void orderQuery.refetch()}
                    />
                ) : (
                    order && (
                        <>
                            <header className="flex flex-wrap items-end justify-between gap-4">
                                <div>
                                    <p className="label-luxury">CHI TIẾT ĐƠN MUA HÀNG</p>
                                    <h1 className="mt-2 text-3xl text-primary">{order.code}</h1>
                                    <div className="mt-2">{statusBadge(order.status)}</div>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {order.status === "draft" && (
                                        <>
                                            <Link
                                                to="/admin/purchase-orders/$id/edit"
                                                params={{ id: String(id) }}
                                                className={secondaryButtonClass}
                                            >
                                                Sửa nháp
                                            </Link>
                                            <button
                                                type="button"
                                                className={buttonClass}
                                                disabled={action.isPending}
                                                onClick={() => setConfirmAction("issue")}
                                            >
                                                Phát hành PO
                                            </button>
                                        </>
                                    )}
                                    {["ordered", "partially_received"].includes(order.status) && (
                                        <button
                                            type="button"
                                            className={buttonClass}
                                            onClick={() => setDrawer("receipt")}
                                        >
                                            Nhận hàng
                                        </button>
                                    )}
                                    {["ordered", "partially_received", "received"].includes(
                                        order.status,
                                    ) &&
                                        order.payment_status !== "paid" && (
                                            <button
                                                type="button"
                                                className={secondaryButtonClass}
                                                onClick={() => setPaymentOpen(true)}
                                            >
                                                Ghi nhận thanh toán
                                            </button>
                                        )}
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <button
                                                type="button"
                                                className={secondaryButtonClass}
                                                aria-label="Thao tác khác"
                                            >
                                                ⋯
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            {order.receipts.some((receipt) =>
                                                receipt.items.some(
                                                    (item) =>
                                                        Number(item.quantity) >
                                                        Number(item.returned_quantity),
                                                ),
                                            ) && (
                                                <DropdownMenuItem
                                                    onSelect={() => setDrawer("return")}
                                                >
                                                    Trả hàng nhà cung cấp
                                                </DropdownMenuItem>
                                            )}
                                            {["draft", "ordered"].includes(order.status) &&
                                                order.payment_status === "unpaid" && (
                                                    <DropdownMenuItem
                                                        onSelect={() => {
                                                            setConfirmAction("cancel");
                                                        }}
                                                    >
                                                        Hủy đơn mua
                                                    </DropdownMenuItem>
                                                )}
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    document
                                                        .getElementById("purchase-history")
                                                        ?.scrollIntoView({ behavior: "smooth" })
                                                }
                                            >
                                                Xem lịch sử
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </header>
                            <section className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <span className="text-xs text-muted-foreground">
                                        Nhà cung cấp
                                    </span>
                                    <p className="font-medium">{order.supplier.name}</p>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground">Kho nhận</span>
                                    <p className="font-medium">{order.warehouse.name}</p>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground">Ngày đặt</span>
                                    <p className="font-medium">
                                        {new Date(order.created_at).toLocaleDateString("vi-VN")}
                                    </p>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground">Tổng tiền</span>
                                    <p className="font-medium">{money(order.total_amount)}</p>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground">
                                        Ngày giao dự kiến
                                    </span>
                                    <p className="font-medium">
                                        {order.expected_delivery_date
                                            ? new Date(
                                                  order.expected_delivery_date,
                                              ).toLocaleDateString("vi-VN")
                                            : "Chưa xác định"}
                                    </p>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground">
                                        Mã nhà cung cấp
                                    </span>
                                    <p className="font-medium">
                                        {order.supplier_order_reference || "—"}
                                    </p>
                                </div>
                                {order.note && (
                                    <p className="text-sm sm:col-span-2 lg:col-span-4">
                                        Ghi chú: {order.note}
                                    </p>
                                )}
                            </section>
                            <section className="space-y-3 rounded-xl border bg-card p-5">
                                <h2 className="text-xl text-primary">Thanh toán nhà cung cấp</h2>
                                <p className="text-sm text-muted-foreground">
                                    Chỉ ghi nhận khoản đã thanh toán bên ngoài ERP.
                                </p>
                                <div className="flex flex-wrap gap-4 text-sm">
                                    <span>
                                        Trạng thái:{" "}
                                        <strong>
                                            {order.payment_status === "paid"
                                                ? "Đã thanh toán"
                                                : order.payment_status === "partially_paid"
                                                  ? "Thanh toán một phần"
                                                  : "Chưa thanh toán"}
                                        </strong>
                                    </span>
                                    <span>
                                        Đã ghi nhận: <strong>{money(order.paid_amount)}</strong>
                                    </span>
                                    <span>
                                        Còn lại:{" "}
                                        <strong>
                                            {money(
                                                Math.max(
                                                    0,
                                                    Number(order.total_amount) -
                                                        Number(order.paid_amount),
                                                ),
                                            )}
                                        </strong>
                                    </span>
                                </div>
                                {order.payments?.map((payment) => (
                                    <p key={payment.id} className="border-t pt-2 text-sm">
                                        {money(payment.amount)} · {payment.payment_method} ·{" "}
                                        {new Date(payment.paid_at).toLocaleString("vi-VN")}
                                        {payment.external_reference
                                            ? ` · ${payment.external_reference}`
                                            : ""}
                                    </p>
                                ))}
                            </section>
                            <section className="rounded-xl border bg-card p-5">
                                <h2 className="text-xl text-primary">Sản phẩm</h2>
                                <div className="mt-4 overflow-x-auto">
                                    <table className="w-full min-w-[600px] text-left text-sm">
                                        <thead className="border-b text-muted-foreground">
                                            <tr>
                                                <th className="p-3">Sản phẩm / SKU</th>
                                                <th className="p-3">Đặt</th>
                                                <th className="p-3">Đã nhận</th>
                                                <th className="p-3">Còn nhận</th>
                                                <th className="p-3">Giá mua</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {order.items.map((item) => (
                                                <tr key={item.id} className="border-b">
                                                    <td className="p-3">
                                                        {item.name_snapshot}
                                                        <span className="block font-mono text-xs">
                                                            {item.sku_snapshot}
                                                        </span>
                                                    </td>
                                                    <td className="p-3 tabular-nums">
                                                        {formatProductQuantity(
                                                            item.ordered_quantity,
                                                        )}
                                                    </td>
                                                    <td className="p-3 tabular-nums">
                                                        {formatProductQuantity(
                                                            item.received_quantity,
                                                        )}
                                                    </td>
                                                    <td className="p-3 tabular-nums">
                                                        {formatProductQuantity(
                                                            String(
                                                                Number(item.ordered_quantity) -
                                                                    Number(item.received_quantity),
                                                            ),
                                                        )}
                                                    </td>
                                                    <td className="p-3 tabular-nums">
                                                        {money(item.unit_price)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                            <section
                                id="purchase-history"
                                className="space-y-4 rounded-xl border bg-card p-5"
                            >
                                <h2 className="text-xl text-primary">Lịch sử nhận / trả hàng</h2>
                                {!order.receipts.length && !order.returns.length && (
                                    <p className="text-sm text-muted-foreground">
                                        PO chưa có phiếu nhận hoặc trả hàng.
                                    </p>
                                )}
                                {order.receipts.map((receipt) => (
                                    <div key={receipt.id} className="rounded-lg border p-3 text-sm">
                                        <strong>Phiếu nhận {receipt.code}</strong>
                                        <span className="ml-2 text-muted-foreground">
                                            {new Date(receipt.received_at).toLocaleString("vi-VN")}
                                        </span>
                                        {receipt.items.map((item) => (
                                            <p key={item.id} className="mt-1">
                                                {item.order_item?.sku_snapshot} · nhận{" "}
                                                {formatProductQuantity(item.quantity)} · đã trả{" "}
                                                {formatProductQuantity(item.returned_quantity)}
                                            </p>
                                        ))}
                                    </div>
                                ))}
                                {order.returns.map((item) => (
                                    <div key={item.id} className="rounded-lg border p-3 text-sm">
                                        <strong>Phiếu trả {item.code}</strong>
                                        <span className="ml-2 text-muted-foreground">
                                            {new Date(item.returned_at).toLocaleString("vi-VN")}
                                        </span>
                                        <p>Lý do: {item.reason}</p>
                                        {item.items.map((line) => (
                                            <p key={line.id}>
                                                {order.receipts
                                                    .flatMap((receipt) => receipt.items)
                                                    .find(
                                                        (receiptItem) =>
                                                            receiptItem.id ===
                                                            line.goods_receipt_item_id,
                                                    )?.order_item?.sku_snapshot ??
                                                    `Dòng nhận #${line.goods_receipt_item_id}`}
                                                : trả {formatProductQuantity(line.quantity)}
                                            </p>
                                        ))}
                                    </div>
                                ))}
                            </section>
                            {drawer && (
                                <PurchaseOperationDrawer
                                    key={`${drawer}-${id}`}
                                    mode={drawer}
                                    order={order}
                                    onClose={() => setDrawer(null)}
                                    onSuccess={refresh}
                                />
                            )}
                            {paymentOpen && (
                                <PurchasePaymentDialog
                                    order={order}
                                    onClose={() => setPaymentOpen(false)}
                                    onSuccess={refresh}
                                />
                            )}
                            <Dialog
                                open={confirmAction !== null}
                                onOpenChange={(open) => {
                                    if (!open) setConfirmAction(null);
                                }}
                            >
                                <DialogContent className="w-[calc(100%-2rem)] max-w-md">
                                    <DialogHeader>
                                        <DialogTitle>
                                            {confirmAction === "issue"
                                                ? "Phát hành đơn mua?"
                                                : "Hủy đơn mua?"}
                                        </DialogTitle>
                                        <DialogDescription>
                                            {confirmAction === "issue"
                                                ? "Đơn mua sẽ được gửi vào quy trình nhận hàng. Tồn kho chỉ tăng khi xác nhận nhận hàng."
                                                : "Bạn có chắc muốn hủy đơn mua này?"}
                                        </DialogDescription>
                                    </DialogHeader>
                                    <DialogFooter>
                                        <button
                                            type="button"
                                            className={secondaryButtonClass}
                                            onClick={() => setConfirmAction(null)}
                                        >
                                            Quay lại
                                        </button>
                                        <button
                                            type="button"
                                            className={buttonClass}
                                            disabled={action.isPending}
                                            onClick={() => {
                                                if (confirmAction)
                                                    action.mutate(confirmAction, {
                                                        onSuccess: () => setConfirmAction(null),
                                                    });
                                            }}
                                        >
                                            {action.isPending ? "Đang xử lý..." : "Xác nhận"}
                                        </button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        </>
                    )
                )}
            </div>
        </ProductAdminGuard>
    );
}

function PurchasePaymentDialog({
    order,
    onClose,
    onSuccess,
}: {
    order: PurchaseOrder;
    onClose: () => void;
    onSuccess: () => Promise<void>;
}) {
    const remaining = Math.max(0, Number(order.total_amount) - Number(order.paid_amount));
    const [amount, setAmount] = useState("");
    const [method, setMethod] = useState("bank_transfer");
    const [paidAt, setPaidAt] = useState(() =>
        new Date().toLocaleString("sv-SE").replace(" ", "T").slice(0, 16),
    );
    const [reference, setReference] = useState("");
    const [note, setNote] = useState("");
    const [confirmed, setConfirmed] = useState(false);
    const operationKey = useRef(crypto.randomUUID());
    const payment = useMutation({
        mutationFn: () =>
            procurementApi.recordPayment(order.id, {
                operation_key: operationKey.current,
                amount,
                payment_method: method,
                paid_at: paidAt,
                external_reference: reference.trim(),
                note: note.trim(),
            }),
        onSuccess: async () => {
            await onSuccess();
            toast.success("Đã ghi nhận thanh toán nhà cung cấp.");
            onClose();
        },
        onError: (error) => toast.error(errorMessage(error)),
    });
    const valid =
        /^(?:[1-9][0-9]{0,14})(?:\.[0-9]{1,2})?$/.test(amount) &&
        Number(amount) <= remaining &&
        Boolean(method.trim()) &&
        Boolean(paidAt);

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !payment.isPending) onClose();
            }}
        >
            <DialogContent className="w-[calc(100%-2rem)] max-w-lg max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Ghi nhận thanh toán</DialogTitle>
                    <DialogDescription>
                        Khoản thanh toán nhà cung cấp đã thực hiện bên ngoài ERP. Còn lại{" "}
                        {money(remaining)}.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-3 text-sm">
                    <label>
                        Số tiền đã thanh toán *
                        <input
                            className={fieldClass}
                            inputMode="decimal"
                            value={amount}
                            onChange={(event) => {
                                setAmount(event.target.value);
                                setConfirmed(false);
                            }}
                        />
                    </label>
                    <label>
                        Phương thức *
                        <select
                            className={fieldClass}
                            value={method}
                            onChange={(event) => setMethod(event.target.value)}
                        >
                            <option value="bank_transfer">Chuyển khoản</option>
                            <option value="cash">Tiền mặt</option>
                            <option value="other">Khác</option>
                        </select>
                    </label>
                    <label>
                        Ngày thanh toán *
                        <input
                            className={fieldClass}
                            type="datetime-local"
                            value={paidAt}
                            onChange={(event) => setPaidAt(event.target.value)}
                        />
                    </label>
                    <label>
                        Mã tham chiếu
                        <input
                            className={fieldClass}
                            value={reference}
                            onChange={(event) => setReference(event.target.value)}
                        />
                    </label>
                    <label>
                        Ghi chú
                        <textarea
                            className={fieldClass}
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                        />
                    </label>
                </div>
                {confirmed && (
                    <p className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm">
                        Xác nhận đã thanh toán {money(amount)} cho {order.supplier.name} bên ngoài
                        ERP?
                    </p>
                )}
                <DialogFooter className="gap-2">
                    <button
                        type="button"
                        className={secondaryButtonClass}
                        disabled={payment.isPending}
                        onClick={onClose}
                    >
                        Hủy
                    </button>
                    <button
                        type="button"
                        className={buttonClass}
                        disabled={!valid || payment.isPending}
                        onClick={() => (confirmed ? payment.mutate() : setConfirmed(true))}
                    >
                        {payment.isPending
                            ? "Đang lưu..."
                            : confirmed
                              ? "Xác nhận ghi nhận"
                              : "Tiếp tục"}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function PurchaseOperationDrawer({
    mode,
    order,
    onClose,
    onSuccess,
}: {
    mode: "receipt" | "return";
    order: PurchaseOrder;
    onClose: () => void;
    onSuccess: () => Promise<void>;
}) {
    const [quantities, setQuantities] = useState<Record<number, string>>({});
    const [reference, setReference] = useState("");
    const [note, setNote] = useState("");
    const [reason, setReason] = useState("");
    const [key, setKey] = useState(() => crypto.randomUUID());
    const [confirm, setConfirm] = useState(false);
    const [formError, setFormError] = useState("");
    const submitting = useRef(false);
    const rows =
        mode === "receipt"
            ? order.items
                  .map((item) => ({
                      id: item.id,
                      label: `${item.sku_snapshot} · ${item.name_snapshot}`,
                      available: Number(item.ordered_quantity) - Number(item.received_quantity),
                  }))
                  .filter((item) => item.available > 0)
            : order.receipts
                  .flatMap((receipt) =>
                      receipt.items.map((item) => ({
                          id: item.id,
                          label: `${item.order_item?.sku_snapshot ?? "SKU"} · ${receipt.code}`,
                          available: Number(item.quantity) - Number(item.returned_quantity),
                      })),
                  )
                  .filter((item) => item.available > 0);
    const selected = rows.filter(
        (row) => quantities[row.id] && isPositiveProductQuantity(quantities[row.id]!),
    );
    const operation = useMutation({
        mutationFn: async (): Promise<void> => {
            if (mode === "receipt") {
                await procurementApi.receive(order.id, {
                    operation_key: key,
                    supplier_reference: reference.trim(),
                    note: note.trim(),
                    items: selected.map((row) => ({
                        purchase_order_item_id: row.id,
                        quantity: quantities[row.id]!,
                    })),
                });
            } else {
                await procurementApi.returnGoods(order.id, {
                    operation_key: key,
                    reason: [reason.trim(), note.trim()].filter(Boolean).join("\n"),
                    items: selected.map((row) => ({
                        goods_receipt_item_id: row.id,
                        quantity: quantities[row.id]!,
                    })),
                });
            }
        },
    });
    const review = () => {
        if (
            !selected.length ||
            Object.values(quantities).some(
                (quantity) => quantity !== "" && !isPositiveProductQuantity(quantity),
            ) ||
            selected.some((row) => Number(quantities[row.id]) > row.available)
        ) {
            setFormError(
                "Nhập ít nhất một số lượng nguyên dương, không vượt số còn nhận hoặc có thể trả.",
            );
            return;
        }
        if (mode === "return" && !reason.trim()) {
            setFormError("Vui lòng nhập lý do trả hàng.");
            return;
        }
        if (
            mode === "return" &&
            [reason.trim(), note.trim()].filter(Boolean).join("\n").length > 2000
        ) {
            setFormError("Lý do và ghi chú không được vượt quá 2000 ký tự.");
            return;
        }
        setFormError("");
        setConfirm(true);
    };
    const submit = async () => {
        if (submitting.current || operation.isPending) return;
        submitting.current = true;
        try {
            await operation.mutateAsync();
            await onSuccess();
            toast.success(
                mode === "receipt"
                    ? "Đã nhận hàng và cập nhật tồn kho."
                    : "Đã ghi nhận trả hàng nhà cung cấp.",
            );
            setConfirm(false);
            onClose();
        } catch (error) {
            setFormError(errorMessage(error));
            toast.error(errorMessage(error));
        } finally {
            submitting.current = false;
        }
    };
    const title = mode === "receipt" ? `Nhận hàng ${order.code}` : "Trả hàng nhà cung cấp";
    return (
        <>
            <Sheet
                open
                onOpenChange={(open) => {
                    if (!open && !confirm && !operation.isPending) onClose();
                }}
            >
                <SheetContent
                    side="right"
                    overlayClassName="bg-black/35"
                    className="flex h-dvh w-full max-w-none flex-col p-0 sm:w-[70vw] sm:max-w-none lg:w-[560px]"
                >
                    <SheetHeader className="shrink-0 border-b px-5 py-5 pr-12 text-left">
                        <SheetTitle className="text-primary">{title}</SheetTitle>
                        <SheetDescription>
                            Kho {order.warehouse.name}.{" "}
                            {mode === "receipt"
                                ? "Có thể nhận nhiều lần; mỗi lần tạo phiếu và biến động riêng."
                                : "Trả từ hàng đã nhận; tồn kho sẽ giảm."}
                        </SheetDescription>
                    </SheetHeader>
                    <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5">
                        <p className="text-sm">
                            {mode === "receipt" ? "Ngày nhận" : "Ngày trả"}:{" "}
                            {new Date().toLocaleDateString("vi-VN")} (ghi khi xác nhận)
                        </p>
                        <div className="space-y-3">
                            {rows.map((row) => (
                                <label
                                    key={row.id}
                                    className="grid gap-2 rounded-lg border p-3 text-sm"
                                >
                                    <strong>{row.label}</strong>
                                    <span>
                                        Còn {mode === "receipt" ? "nhận" : "có thể trả"}:{" "}
                                        {formatProductQuantity(String(row.available))}
                                    </span>
                                    <input
                                        aria-label={`Số lượng ${row.label}`}
                                        type="number"
                                        min="1"
                                        step="1"
                                        className={fieldClass}
                                        value={quantities[row.id] ?? ""}
                                        onChange={(event) => {
                                            setQuantities((current) => ({
                                                ...current,
                                                [row.id]: event.target.value,
                                            }));
                                            setKey(crypto.randomUUID());
                                        }}
                                        placeholder="Số lượng lần này"
                                    />
                                </label>
                            ))}
                        </div>
                        {mode === "receipt" ? (
                            <>
                                <label className="block text-sm">
                                    Mã phiếu giao từ nhà cung cấp
                                    <input
                                        className={fieldClass}
                                        value={reference}
                                        onChange={(event) => {
                                            setReference(event.target.value);
                                            setKey(crypto.randomUUID());
                                        }}
                                    />
                                </label>
                                <label className="block text-sm">
                                    Ghi chú
                                    <textarea
                                        className={fieldClass}
                                        value={note}
                                        onChange={(event) => {
                                            setNote(event.target.value);
                                            setKey(crypto.randomUUID());
                                        }}
                                    />
                                </label>
                            </>
                        ) : (
                            <>
                                <label className="block text-sm">
                                    Lý do trả hàng *
                                    <textarea
                                        className={fieldClass}
                                        value={reason}
                                        onChange={(event) => {
                                            setReason(event.target.value);
                                            setKey(crypto.randomUUID());
                                        }}
                                    />
                                </label>
                                <label className="block text-sm">
                                    Ghi chú
                                    <textarea
                                        className={fieldClass}
                                        value={note}
                                        onChange={(event) => {
                                            setNote(event.target.value);
                                            setKey(crypto.randomUUID());
                                        }}
                                    />
                                    <span className="text-xs text-muted-foreground">
                                        Ghi chú được lưu cùng lý do trên phiếu trả.
                                    </span>
                                </label>
                            </>
                        )}
                        {formError && (
                            <p role="alert" className="text-sm text-red-700">
                                {formError}
                            </p>
                        )}
                    </div>
                    <div className="flex shrink-0 justify-end gap-2 border-t bg-background px-5 py-4">
                        <button type="button" className={secondaryButtonClass} onClick={onClose}>
                            Hủy
                        </button>
                        <button type="button" className={buttonClass} onClick={review}>
                            Xem và xác nhận
                        </button>
                    </div>
                </SheetContent>
            </Sheet>
            <Dialog
                open={confirm}
                onOpenChange={(open) => {
                    if (!open && !operation.isPending) setConfirm(false);
                }}
            >
                <DialogContent className="w-[min(94vw,480px)]">
                    <DialogHeader>
                        <DialogTitle>Xác nhận {title.toLowerCase()}</DialogTitle>
                        <DialogDescription>
                            {mode === "receipt"
                                ? "Xác nhận sẽ tạo phiếu nhận, movement và tăng tồn kho."
                                : "Xác nhận sẽ tạo phiếu trả, movement âm và giảm tồn kho."}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2 text-sm">
                        <p>
                            {order.supplier.name} · {order.warehouse.name}
                        </p>
                        {selected.map((row) => (
                            <p key={row.id} className="flex justify-between gap-2">
                                <span>{row.label}</span>
                                <strong>{quantities[row.id]}</strong>
                            </p>
                        ))}
                        {mode === "return" && <p>Lý do: {reason}</p>}
                        {note && <p>Ghi chú: {note}</p>}
                    </div>
                    <DialogFooter className="gap-2">
                        <button
                            type="button"
                            className={secondaryButtonClass}
                            disabled={operation.isPending}
                            onClick={() => setConfirm(false)}
                        >
                            Quay lại
                        </button>
                        <button
                            type="button"
                            className={buttonClass}
                            disabled={operation.isPending}
                            onClick={() => void submit()}
                        >
                            {operation.isPending ? "Đang ghi…" : "Xác nhận"}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
