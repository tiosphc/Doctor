import { useEffect, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useNavigate } from "@tanstack/react-router";
import { toast } from "sonner";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Pagination } from "@/components/common/AsyncState";
import { formatProductQuantity, isPositiveProductQuantity } from "@/lib/productQuantity";
import { ApiError, errorMessage, firstFieldErrors } from "@/services/api";
import { commerceCodeMessage } from "@/services/commerceErrors";
import { inventoryApi } from "@/services/inventoryApi";
import { productApi, type CatalogPriceRow } from "@/services/productApi";
import {
    salesOrderApi,
    salesOrderKeys,
    type RetailBuyer,
    type RetailBuyerDetail,
} from "@/services/salesOrderApi";
import type { SalesOrderDraftInput } from "@/types/salesOrder";
import {
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "./ProductAdminShared";

type RetailForm = Omit<SalesOrderDraftInput, "operation_key">;
type SelectedProduct = {
    sku: string;
    product_name: string;
    variant_name: string;
    image_url: string | null;
    unit_symbol: string | null;
    quantity: string;
};

const initialForm: RetailForm = {
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
    shipping_district: null,
    shipping_province: "",
    shipping_country: "VN",
    shipping_postal_code: null,
    delivery_note: null,
    payment_method: "cod",
    items: [],
};

const money = (value: string | number) =>
    `${new Intl.NumberFormat("vi-VN").format(Number(value))} ₫`;

function retailErrorMessage(reason: unknown, sku?: string): string {
    if (!(reason instanceof ApiError)) return errorMessage(reason);
    const messages: Record<string, string> = {
        PRICE_NOT_FOUND: "Sản phẩm này chưa được thiết lập giá Retail.",
        PRICE_AMBIGUOUS: "Giá Retail của sản phẩm chưa rõ ràng. Vui lòng kiểm tra bảng giá.",
        ORDER_PRICE_CHANGED: "Giá Retail vừa thay đổi. Vui lòng xem lại tổng đơn và thử lại.",
        SKU_UNAVAILABLE: "Sản phẩm không còn bán Retail. Vui lòng chọn sản phẩm khác.",
        WAREHOUSE_INACTIVE: "Kho đã ngừng hoạt động. Vui lòng chọn kho khác.",
        BUYER_INVALID: "Khách hàng đã chọn không hợp lệ. Vui lòng chọn lại.",
        ORDER_INVALID_STATE: "Đơn hàng không còn ở trạng thái bản nháp để chỉnh sửa.",
    };
    const specificMessage = reason.code ? messages[reason.code] : undefined;
    if (specificMessage) return specificMessage;
    if (reason.code) {
        return commerceCodeMessage(reason.code, sku, {
            available: reason.details?.available ?? null,
            requested: reason.details?.requested ?? null,
        });
    }
    if (reason.status === 422 && reason.errors["email"])
        return "Email không hợp lệ hoặc đã được sử dụng.";
    if (reason.status === 422)
        return "Thông tin đơn hàng chưa hợp lệ. Vui lòng kiểm tra các trường được đánh dấu.";
    return errorMessage(reason);
}

export function SalesOrderCreatePage({ draftId }: { draftId?: number }) {
    const navigate = useNavigate();
    const [form, setForm] = useState<RetailForm>(initialForm);
    const [products, setProducts] = useState<SelectedProduct[]>([]);
    const [selectedBuyer, setSelectedBuyer] = useState<RetailBuyer | null>(null);
    const [lastShipping, setLastShipping] = useState<RetailBuyerDetail["last_shipping"]>(null);
    const [addressMode, setAddressMode] = useState<"recent" | "other">("other");
    const [buyerSearch, setBuyerSearch] = useState("");
    const [buyerOpen, setBuyerOpen] = useState(false);
    const [newBuyerOpen, setNewBuyerOpen] = useState(false);
    const [newBuyer, setNewBuyer] = useState({ name: "", phone: "", email: "" });
    const [buyerBusy, setBuyerBusy] = useState(false);
    const [productOpen, setProductOpen] = useState(false);
    const [productSearch, setProductSearch] = useState("");
    const [productPage, setProductPage] = useState(1);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const operationKey = useRef(globalThis.crypto.randomUUID());
    const confirmOperationKey = useRef(globalThis.crypto.randomUUID());
    const submitting = useRef(false);
    const buyerSelection = useRef(0);
    const hydratedDraft = useRef<number | null>(null);

    const draftOrder = useQuery({
        queryKey: salesOrderKeys.detail(draftId ?? 0),
        queryFn: () => salesOrderApi.detail(draftId!),
        enabled: draftId !== undefined,
    });

    const warehouses = useQuery({
        queryKey: ["sales-order-warehouses"],
        queryFn: () => inventoryApi.warehouses({ status: "active", per_page: 100 }),
    });
    const buyers = useQuery({
        queryKey: salesOrderKeys.buyers(buyerSearch.trim()),
        queryFn: () => salesOrderApi.buyers(buyerSearch.trim()),
        enabled: buyerOpen,
    });
    const catalog = useQuery({
        queryKey: ["retail-order-catalog", form.warehouse_id, productSearch.trim(), productPage],
        queryFn: () =>
            productApi.catalogPrices("retail", {
                search: productSearch.trim() || undefined,
                status: "active",
                warehouse_id: form.warehouse_id,
                page: productPage,
                per_page: 20,
            }),
        enabled: productOpen && form.warehouse_id > 0,
    });
    const validItems =
        products.length > 0 && products.every((item) => isPositiveProductQuantity(item.quantity));
    const preview = useQuery({
        queryKey: [
            "retail-order-preview",
            form.warehouse_id,
            products.map(({ sku, quantity }) => [sku, quantity]),
        ],
        queryFn: () =>
            salesOrderApi.previewRetail({
                warehouse_id: form.warehouse_id,
                items: products.map(({ sku, quantity }) => ({ sku, quantity })),
            }),
        enabled: form.warehouse_id > 0 && validItems,
        retry: false,
    });

    useEffect(() => {
        if (draftId !== undefined || form.warehouse_id || !warehouses.data) return;
        const defaultWarehouse = warehouses.data.data.find(
            (warehouse) => warehouse.is_default_sales,
        );
        if (defaultWarehouse)
            setForm((current) => ({ ...current, warehouse_id: defaultWarehouse.id }));
    }, [warehouses.data, form.warehouse_id, draftId]);

    useEffect(() => {
        const order = draftOrder.data?.data;
        if (!order || draftId === undefined || hydratedDraft.current === draftId) return;
        if (
            order.order_status !== "draft" ||
            order.sales_channel !== "retail" ||
            order.order_source !== "admin"
        )
            return;
        hydratedDraft.current = draftId;
        setSelectedBuyer({
            id: order.buyer_user_id,
            name: order.buyer.name,
            email: order.buyer.email,
            phone: order.recipient_phone,
        });
        setForm({
            sales_channel: "retail",
            buyer_user_id: order.buyer_user_id,
            warehouse_id: order.warehouse_id,
            currency: "VND",
            recipient_name: order.recipient_name,
            recipient_phone: order.recipient_phone,
            recipient_email: order.recipient_email,
            shipping_address_line1: order.shipping_address_line1,
            shipping_address_line2: order.shipping_address_line2,
            shipping_city: order.shipping_city,
            shipping_district: order.shipping_district,
            shipping_province: order.shipping_province,
            shipping_country: order.shipping_country,
            shipping_postal_code: order.shipping_postal_code,
            delivery_note: order.delivery_note,
            payment_method: order.payment_method === "bank_transfer" ? "bank_transfer" : "cod",
            items: order.items.map((item) => ({ sku: item.sku_snapshot, quantity: item.quantity })),
        });
        setProducts(
            order.items.map((item) => ({
                sku: item.sku_snapshot,
                product_name: item.product_name_snapshot,
                variant_name: item.variant_name_snapshot,
                image_url: item.image_url,
                unit_symbol: item.unit_code_snapshot,
                quantity: String(Number(item.quantity)),
            })),
        );
    }, [draftOrder.data, draftId]);

    const updateForm = (patch: Partial<RetailForm>) => {
        setForm((current) => ({ ...current, ...patch }));
        setErrors({});
        setNotice("");
        operationKey.current = globalThis.crypto.randomUUID();
        confirmOperationKey.current = globalThis.crypto.randomUUID();
    };
    const updateProducts = (next: SelectedProduct[]) => {
        setProducts(next);
        updateForm({ items: next.map(({ sku, quantity }) => ({ sku, quantity })) });
    };
    const chooseBuyer = async (buyer: RetailBuyer) => {
        const selection = ++buyerSelection.current;
        setSelectedBuyer(buyer);
        setBuyerSearch("");
        setBuyerOpen(false);
        setLastShipping(null);
        setAddressMode("other");
        updateForm({
            buyer_user_id: buyer.id,
            recipient_name: buyer.name,
            recipient_phone: buyer.phone ?? "",
            recipient_email: buyer.email,
            shipping_address_line1: "",
            shipping_address_line2: null,
            shipping_city: "",
            shipping_district: null,
            shipping_province: "",
            shipping_postal_code: null,
            delivery_note: null,
        });
        try {
            const response = await salesOrderApi.buyer(buyer.id);
            if (selection !== buyerSelection.current) return;
            if (response.data.last_shipping) {
                setLastShipping(response.data.last_shipping);
                setAddressMode("recent");
                updateForm({
                    ...response.data.last_shipping,
                    buyer_user_id: buyer.id,
                    recipient_name: response.data.last_shipping.recipient_name || buyer.name,
                    recipient_phone:
                        response.data.last_shipping.recipient_phone || buyer.phone || "",
                    recipient_email: response.data.last_shipping.recipient_email || buyer.email,
                });
            }
        } catch (reason) {
            if (selection === buyerSelection.current) toast.error(retailErrorMessage(reason));
        }
    };
    const createBuyer = async () => {
        if (buyerBusy) return;
        if (!newBuyer.name.trim() || !newBuyer.phone.trim() || !newBuyer.email.trim()) {
            toast.error("Vui lòng nhập tên, số điện thoại và email khách hàng.");
            return;
        }
        setBuyerBusy(true);
        try {
            const response = await salesOrderApi.createBuyer({
                name: newBuyer.name.trim(),
                phone: newBuyer.phone.trim(),
                email: newBuyer.email.trim(),
            });
            setNewBuyerOpen(false);
            setNewBuyer({ name: "", phone: "", email: "" });
            toast.success("Đã tạo khách hàng mới.");
            await chooseBuyer(response.data);
        } catch (reason) {
            toast.error(retailErrorMessage(reason));
        } finally {
            setBuyerBusy(false);
        }
    };
    const addProduct = (row: CatalogPriceRow) => {
        const existing = products.find((item) => item.sku === row.sku);
        if (existing) {
            updateProducts(
                products.map((item) =>
                    item.sku === row.sku
                        ? {
                              ...item,
                              quantity: String(
                                  isPositiveProductQuantity(item.quantity)
                                      ? Number(item.quantity) + 1
                                      : 1,
                              ),
                          }
                        : item,
                ),
            );
            toast.success("Đã tăng số lượng sản phẩm trong đơn.");
        } else {
            updateProducts([
                ...products,
                {
                    sku: row.sku,
                    product_name: row.product_name,
                    variant_name: row.variant_name,
                    image_url: row.product_image_url,
                    unit_symbol: row.unit_symbol,
                    quantity: "1",
                },
            ]);
            toast.success("Đã thêm sản phẩm.");
        }
    };
    const useOtherAddress = () => {
        setAddressMode("other");
        updateForm({
            recipient_name: selectedBuyer?.name ?? "",
            recipient_phone: selectedBuyer?.phone ?? "",
            recipient_email: selectedBuyer?.email ?? null,
            shipping_address_line1: "",
            shipping_address_line2: null,
            shipping_city: "",
            shipping_district: null,
            shipping_province: "",
            shipping_postal_code: null,
            delivery_note: null,
        });
    };
    const useRecentAddress = () => {
        if (!lastShipping) return;
        setAddressMode("recent");
        updateForm({ ...lastShipping });
    };
    const validate = (confirm: boolean): Record<string, string> => {
        const next: Record<string, string> = {};
        if (!form.buyer_user_id) next["buyer_user_id"] = "Vui lòng chọn khách hàng.";
        if (!form.warehouse_id) next["warehouse_id"] = "Vui lòng chọn kho xuất hàng.";
        if (!form.recipient_name.trim()) next["recipient_name"] = "Vui lòng nhập tên người nhận.";
        if (!form.recipient_phone.trim())
            next["recipient_phone"] = "Vui lòng nhập số điện thoại người nhận.";
        if (form.recipient_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.recipient_email))
            next["recipient_email"] = "Email người nhận không đúng định dạng.";
        if (!form.shipping_province.trim())
            next["shipping_province"] = "Vui lòng nhập tỉnh/thành phố.";
        if (!form.shipping_city.trim()) next["shipping_city"] = "Vui lòng nhập quận/huyện.";
        if (!form.shipping_district?.trim()) next["shipping_district"] = "Vui lòng nhập phường/xã.";
        if (!form.shipping_address_line1.trim())
            next["shipping_address_line1"] = "Vui lòng nhập địa chỉ cụ thể.";
        if (products.length === 0) next["items"] = "Vui lòng thêm ít nhất một sản phẩm.";
        products.forEach((item, index) => {
            if (!isPositiveProductQuantity(item.quantity))
                next[`items.${index}.quantity`] = "Số lượng phải lớn hơn 0 và là số nguyên.";
        });
        if (!form.payment_method) next["payment_method"] = "Vui lòng chọn phương thức thanh toán.";
        if (confirm && preview.data) {
            preview.data.data.items.forEach((item, index) => {
                if (item.insufficient_stock)
                    next[`items.${index}.quantity`] =
                        `Kho ${selectedWarehouse?.name ?? "đã chọn"} chỉ còn ${formatProductQuantity(item.available_quantity)} sản phẩm khả dụng.`;
            });
        }
        return next;
    };
    const selectedWarehouse = warehouses.data?.data.find(
        (warehouse) => warehouse.id === form.warehouse_id,
    );
    const submit = async (confirm: boolean) => {
        if (submitting.current) return;
        const nextErrors = validate(confirm);
        if (Object.keys(nextErrors).length > 0) {
            setErrors(nextErrors);
            toast.error("Vui lòng kiểm tra các trường được đánh dấu.");
            return;
        }
        if (confirm && (preview.isPending || preview.isFetching || preview.isError)) {
            toast.error("Chưa thể kiểm tra giá và tồn kho. Vui lòng thử lại.");
            return;
        }
        submitting.current = true;
        setBusy(true);
        setNotice("");
        try {
            const payload: SalesOrderDraftInput = {
                ...form,
                operation_key: operationKey.current,
                ...(confirm
                    ? { confirm: true, confirm_operation_key: confirmOperationKey.current }
                    : {}),
                items: products.map(({ sku, quantity }) => ({ sku, quantity })),
            };
            const response =
                draftId === undefined
                    ? await salesOrderApi.create(payload)
                    : await salesOrderApi.updateDraft(draftId, payload);
            toast.success(
                confirm
                    ? `Đã tạo đơn bán lẻ ${response.data.order_code}.`
                    : `Đã lưu bản nháp ${response.data.order_code}.`,
            );
            await navigate({
                to: "/admin/sales-orders/$id",
                params: { id: String(response.data.id) },
            });
        } catch (reason) {
            const message = retailErrorMessage(reason, products[0]?.sku);
            setErrors(firstFieldErrors(reason));
            setNotice(message);
            toast.error(message);
            if (
                reason instanceof ApiError &&
                ["ORDER_PRICE_CHANGED", "PRICE_NOT_FOUND", "INSUFFICIENT_STOCK"].includes(
                    reason.code ?? "",
                )
            ) {
                void preview.refetch();
            }
        } finally {
            submitting.current = false;
            setBusy(false);
        }
    };

    if (
        draftId !== undefined &&
        (draftOrder.isPending || (draftOrder.data && hydratedDraft.current !== draftId))
    ) {
        return (
            <ProductAdminGuard>
                <p className="p-6 text-sm">Đang tải bản nháp...</p>
            </ProductAdminGuard>
        );
    }
    if (
        draftId !== undefined &&
        (draftOrder.isError ||
            !draftOrder.data ||
            draftOrder.data.data.order_status !== "draft" ||
            draftOrder.data.data.sales_channel !== "retail" ||
            draftOrder.data.data.order_source !== "admin")
    ) {
        return (
            <ProductAdminGuard>
                <p role="alert" className="p-6 text-sm text-red-700">
                    Không thể chỉnh sửa bản nháp này.
                </p>
            </ProductAdminGuard>
        );
    }

    return (
        <ProductAdminGuard>
            <div className="mx-auto max-w-6xl space-y-5 pb-24">
                <header>
                    <Link to="/admin/sales-orders/retail" className={secondaryButtonClass}>
                        ← Đơn hàng
                    </Link>
                    <h1 className="mt-4 text-2xl font-semibold text-primary sm:text-3xl">
                        {draftId === undefined ? "Tạo đơn bán lẻ" : "Chỉnh sửa bản nháp Retail"}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Tạo đơn hàng Retail cho khách. Giá được lấy tự động từ bảng giá Retail.
                    </p>
                </header>
                {notice && (
                    <p
                        role="alert"
                        className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"
                    >
                        {notice}
                    </p>
                )}

                <section className="rounded-xl border bg-card p-4 sm:p-5">
                    <h2 className="text-lg font-semibold text-primary">Khách hàng & kho</h2>
                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        <div className="relative space-y-2">
                            <label htmlFor="retail-buyer-search" className="text-sm font-medium">
                                Khách hàng *
                            </label>
                            <input
                                id="retail-buyer-search"
                                className={fieldClass}
                                role="combobox"
                                aria-expanded={buyerOpen}
                                aria-controls="retail-buyer-options"
                                autoComplete="off"
                                value={buyerSearch}
                                onFocus={() => setBuyerOpen(true)}
                                onChange={(event) => {
                                    buyerSelection.current += 1;
                                    setSelectedBuyer(null);
                                    setLastShipping(null);
                                    updateForm({ buyer_user_id: 0 });
                                    setBuyerSearch(event.target.value);
                                    setBuyerOpen(true);
                                }}
                                placeholder="Tìm tên, số điện thoại hoặc email..."
                            />
                            {buyerOpen && (
                                <div
                                    id="retail-buyer-options"
                                    className="absolute z-20 max-h-64 w-full overflow-y-auto rounded-lg border bg-card shadow-lg"
                                >
                                    {buyers.isPending && (
                                        <p className="p-3 text-sm">Đang tìm khách hàng...</p>
                                    )}
                                    {buyers.isError && (
                                        <p role="alert" className="p-3 text-sm text-red-700">
                                            Không thể tải khách hàng.
                                        </p>
                                    )}
                                    {buyers.data?.data.length === 0 && (
                                        <p className="p-3 text-sm text-muted-foreground">
                                            Không tìm thấy khách hàng.
                                        </p>
                                    )}
                                    {buyers.data?.data.map((buyer) => (
                                        <button
                                            key={buyer.id}
                                            type="button"
                                            className="block w-full border-b px-3 py-2 text-left text-sm hover:bg-muted focus:bg-muted"
                                            onClick={() => void chooseBuyer(buyer)}
                                        >
                                            <strong>{buyer.name}</strong>
                                            <span className="block text-muted-foreground">
                                                {buyer.phone || "Chưa có số điện thoại"} ·{" "}
                                                {buyer.email}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}
                            {selectedBuyer && (
                                <div className="flex items-center justify-between gap-2 rounded-lg bg-muted px-3 py-2 text-sm">
                                    <span>
                                        <strong>{selectedBuyer.name}</strong> ·{" "}
                                        {selectedBuyer.phone || selectedBuyer.email}
                                    </span>
                                    <button
                                        type="button"
                                        className="text-primary underline"
                                        onClick={() => {
                                            buyerSelection.current += 1;
                                            setSelectedBuyer(null);
                                            setLastShipping(null);
                                            updateForm({ buyer_user_id: 0 });
                                        }}
                                    >
                                        Đổi
                                    </button>
                                </div>
                            )}
                            {errors["buyer_user_id"] && (
                                <p role="alert" className="text-xs text-red-700">
                                    {errors["buyer_user_id"]}
                                </p>
                            )}
                            <button
                                type="button"
                                className="text-sm font-medium text-primary underline underline-offset-2"
                                onClick={() => {
                                    setBuyerOpen(false);
                                    setNewBuyerOpen(true);
                                }}
                            >
                                + Tạo khách hàng mới
                            </button>
                        </div>
                        <div className="space-y-2">
                            <label htmlFor="retail-warehouse" className="text-sm font-medium">
                                Kho xuất hàng *
                            </label>
                            <select
                                id="retail-warehouse"
                                className={fieldClass}
                                value={form.warehouse_id || ""}
                                onChange={(event) =>
                                    updateForm({ warehouse_id: Number(event.target.value) })
                                }
                            >
                                <option value="">Chọn kho xuất hàng</option>
                                {warehouses.data?.data.map((warehouse) => (
                                    <option key={warehouse.id} value={warehouse.id}>
                                        {warehouse.code} · {warehouse.name}
                                    </option>
                                ))}
                            </select>
                            {errors["warehouse_id"] && (
                                <p role="alert" className="text-xs text-red-700">
                                    {errors["warehouse_id"]}
                                </p>
                            )}
                        </div>
                    </div>
                </section>

                <section className="rounded-xl border bg-card p-4 sm:p-5">
                    <h2 className="text-lg font-semibold text-primary">Giao hàng</h2>
                    {lastShipping && (
                        <div className="mt-3 grid gap-2 text-sm">
                            <label className="flex items-start gap-2 rounded-lg border p-3">
                                <input
                                    type="radio"
                                    checked={addressMode === "recent"}
                                    onChange={useRecentAddress}
                                />
                                <span>
                                    <strong>Địa chỉ giao gần nhất</strong>
                                    <span className="mt-1 block text-muted-foreground">
                                        {lastShipping.recipient_name} ·{" "}
                                        {lastShipping.recipient_phone}
                                        <br />
                                        {[
                                            lastShipping.shipping_address_line1,
                                            lastShipping.shipping_district,
                                            lastShipping.shipping_city,
                                            lastShipping.shipping_province,
                                        ]
                                            .filter(Boolean)
                                            .join(", ")}
                                    </span>
                                </span>
                            </label>
                            <label className="flex items-center gap-2 rounded-lg border p-3">
                                <input
                                    type="radio"
                                    checked={addressMode === "other"}
                                    onChange={useOtherAddress}
                                />
                                Sử dụng địa chỉ khác
                            </label>
                        </div>
                    )}
                    {(!lastShipping || addressMode === "other") && (
                        <div className="mt-4 grid gap-3 sm:grid-cols-2">
                            {(
                                [
                                    ["recipient_name", "Tên người nhận *"],
                                    ["recipient_phone", "Số điện thoại *"],
                                    ["recipient_email", "Email"],
                                    ["shipping_province", "Tỉnh/Thành phố *"],
                                    ["shipping_city", "Quận/Huyện *"],
                                    ["shipping_district", "Phường/Xã *"],
                                    ["shipping_address_line1", "Địa chỉ cụ thể *"],
                                    ["delivery_note", "Ghi chú giao hàng"],
                                ] as const
                            ).map(([name, label]) => (
                                <label
                                    key={name}
                                    className={`grid gap-1 text-sm ${name === "shipping_address_line1" || name === "delivery_note" ? "sm:col-span-2" : ""}`}
                                >
                                    <span>{label}</span>
                                    <input
                                        className={fieldClass}
                                        value={form[name] ?? ""}
                                        onChange={(event) =>
                                            updateForm({ [name]: event.target.value })
                                        }
                                    />
                                    {errors[name] && (
                                        <span role="alert" className="text-xs text-red-700">
                                            {errors[name]}
                                        </span>
                                    )}
                                </label>
                            ))}
                        </div>
                    )}
                    {lastShipping && addressMode === "recent" && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Dữ liệu được lấy từ đơn Retail gần nhất của khách. Kiểm tra lại trước
                            khi tạo đơn.
                        </p>
                    )}
                </section>

                <section className="rounded-xl border bg-card p-4 sm:p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold text-primary">Sản phẩm</h2>
                        <button
                            type="button"
                            className={buttonClass}
                            onClick={() => {
                                if (!form.warehouse_id) {
                                    toast.error("Vui lòng chọn kho xuất hàng trước.");
                                    return;
                                }
                                setProductOpen(true);
                            }}
                        >
                            + Thêm sản phẩm
                        </button>
                    </div>
                    {products.length === 0 && (
                        <p className="mt-4 text-sm text-muted-foreground">
                            Chưa có sản phẩm trong đơn.
                        </p>
                    )}
                    {errors["items"] && (
                        <p role="alert" className="mt-2 text-xs text-red-700">
                            {errors["items"]}
                        </p>
                    )}
                    {products.length > 0 && (
                        <div className="mt-4 overflow-x-auto">
                            <table className="hidden w-full min-w-[750px] text-left text-sm md:table">
                                <thead className="border-b text-muted-foreground">
                                    <tr>
                                        <th className="py-2">Sản phẩm</th>
                                        <th>SKU</th>
                                        <th>Đơn giá Retail</th>
                                        <th>Số lượng</th>
                                        <th>Thành tiền</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {products.map((product, index) => {
                                        const line = preview.data?.data.items.find(
                                            (item) => item.sku === product.sku,
                                        );
                                        return (
                                            <tr
                                                key={product.sku}
                                                className="border-b last:border-0"
                                            >
                                                <td className="py-3">
                                                    <div className="flex items-center gap-2">
                                                        {product.image_url && (
                                                            <img
                                                                src={product.image_url}
                                                                alt=""
                                                                className="h-10 w-10 rounded object-cover"
                                                            />
                                                        )}
                                                        <span>
                                                            {product.product_name} ·{" "}
                                                            {product.variant_name}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="font-mono">{product.sku}</td>
                                                <td>
                                                    {line
                                                        ? money(line.unit_price)
                                                        : "Đang kiểm tra..."}
                                                </td>
                                                <td>
                                                    <div className="flex items-center gap-1">
                                                        <button
                                                            type="button"
                                                            className={secondaryButtonClass}
                                                            aria-label={`Giảm số lượng ${product.sku}`}
                                                            disabled={Number(product.quantity) <= 1}
                                                            onClick={() =>
                                                                updateProducts(
                                                                    products.map((row) =>
                                                                        row.sku === product.sku
                                                                            ? {
                                                                                  ...row,
                                                                                  quantity: String(
                                                                                      Number(
                                                                                          row.quantity,
                                                                                      ) - 1,
                                                                                  ),
                                                                              }
                                                                            : row,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            −
                                                        </button>
                                                        <input
                                                            aria-label={`Số lượng ${product.sku}`}
                                                            className={`${fieldClass} w-20 text-center`}
                                                            inputMode="numeric"
                                                            value={product.quantity}
                                                            onChange={(event) =>
                                                                updateProducts(
                                                                    products.map((row) =>
                                                                        row.sku === product.sku
                                                                            ? {
                                                                                  ...row,
                                                                                  quantity:
                                                                                      event.target
                                                                                          .value,
                                                                              }
                                                                            : row,
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                        <button
                                                            type="button"
                                                            className={secondaryButtonClass}
                                                            aria-label={`Tăng số lượng ${product.sku}`}
                                                            onClick={() =>
                                                                updateProducts(
                                                                    products.map((row) =>
                                                                        row.sku === product.sku
                                                                            ? {
                                                                                  ...row,
                                                                                  quantity: String(
                                                                                      Number(
                                                                                          row.quantity ||
                                                                                              0,
                                                                                      ) + 1,
                                                                                  ),
                                                                              }
                                                                            : row,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            +
                                                        </button>
                                                    </div>
                                                    {errors[`items.${index}.quantity`] && (
                                                        <p
                                                            role="alert"
                                                            className="text-xs text-red-700"
                                                        >
                                                            {errors[`items.${index}.quantity`]}
                                                        </p>
                                                    )}
                                                    {line?.insufficient_stock && (
                                                        <p className="text-xs text-red-700">
                                                            Kho {selectedWarehouse?.name} chỉ còn{" "}
                                                            {formatProductQuantity(
                                                                line.available_quantity,
                                                            )}{" "}
                                                            khả dụng.
                                                        </p>
                                                    )}
                                                </td>
                                                <td>{line ? money(line.line_total) : "—"}</td>
                                                <td>
                                                    <button
                                                        type="button"
                                                        className="text-red-700 underline"
                                                        onClick={() =>
                                                            updateProducts(
                                                                products.filter(
                                                                    (row) =>
                                                                        row.sku !== product.sku,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        Xóa
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                            <div className="grid gap-3 md:hidden">
                                {products.map((product, index) => {
                                    const line = preview.data?.data.items.find(
                                        (item) => item.sku === product.sku,
                                    );
                                    return (
                                        <div
                                            key={product.sku}
                                            className="space-y-2 rounded-lg border p-3 text-sm"
                                        >
                                            <div className="flex justify-between gap-2">
                                                <strong>
                                                    {product.product_name} · {product.variant_name}
                                                </strong>
                                                <button
                                                    type="button"
                                                    className="text-red-700 underline"
                                                    onClick={() =>
                                                        updateProducts(
                                                            products.filter(
                                                                (row) => row.sku !== product.sku,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    Xóa
                                                </button>
                                            </div>
                                            <p className="font-mono text-xs">{product.sku}</p>
                                            <p>
                                                Đơn giá:{" "}
                                                {line ? money(line.unit_price) : "Đang kiểm tra..."}
                                            </p>
                                            <label className="grid gap-1">
                                                Số lượng
                                                <input
                                                    className={fieldClass}
                                                    inputMode="numeric"
                                                    value={product.quantity}
                                                    onChange={(event) =>
                                                        updateProducts(
                                                            products.map((row) =>
                                                                row.sku === product.sku
                                                                    ? {
                                                                          ...row,
                                                                          quantity:
                                                                              event.target.value,
                                                                      }
                                                                    : row,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </label>
                                            {errors[`items.${index}.quantity`] && (
                                                <p role="alert" className="text-xs text-red-700">
                                                    {errors[`items.${index}.quantity`]}
                                                </p>
                                            )}
                                            {line?.insufficient_stock && (
                                                <p className="text-xs text-red-700">
                                                    Kho {selectedWarehouse?.name} chỉ còn{" "}
                                                    {formatProductQuantity(line.available_quantity)}{" "}
                                                    khả dụng.
                                                </p>
                                            )}
                                            <p className="font-semibold">
                                                Thành tiền: {line ? money(line.line_total) : "—"}
                                            </p>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                    {preview.isError && (
                        <p role="alert" className="mt-3 text-sm text-red-700">
                            {retailErrorMessage(preview.error, products[0]?.sku)}
                        </p>
                    )}
                </section>

                <section className="grid gap-5 rounded-xl border bg-card p-4 sm:p-5 md:grid-cols-2">
                    <div>
                        <h2 className="text-lg font-semibold text-primary">Thanh toán</h2>
                        <div className="mt-3 space-y-2 text-sm">
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="retail-payment"
                                    checked={form.payment_method === "cod"}
                                    onChange={() => updateForm({ payment_method: "cod" })}
                                />
                                COD
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="retail-payment"
                                    checked={form.payment_method === "bank_transfer"}
                                    onChange={() => updateForm({ payment_method: "bank_transfer" })}
                                />
                                Chuyển khoản
                            </label>
                        </div>
                        <p className="mt-3 text-xs text-muted-foreground">
                            Chọn chuyển khoản không xác nhận đã thanh toán. Tiền được ghi nhận riêng
                            sau khi nhận.
                        </p>
                        {errors["payment_method"] && (
                            <p role="alert" className="text-xs text-red-700">
                                {errors["payment_method"]}
                            </p>
                        )}
                    </div>
                    <div>
                        <h2 className="text-lg font-semibold text-primary">
                            Tóm tắt đơn hàng{" "}
                            <span className="text-sm font-normal text-muted-foreground">· VND</span>
                        </h2>
                        <dl className="mt-3 space-y-2 text-sm">
                            <div className="flex justify-between gap-3">
                                <dt>Tạm tính</dt>
                                <dd>{preview.data ? money(preview.data.data.subtotal) : "—"}</dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt>Giảm giá</dt>
                                <dd>
                                    {preview.data ? money(preview.data.data.discount_total) : "—"}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt>Phí vận chuyển</dt>
                                <dd>
                                    {preview.data ? money(preview.data.data.shipping_total) : "—"}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3 border-t pt-2 text-base font-semibold text-primary">
                                <dt>Tổng cộng</dt>
                                <dd>{preview.data ? money(preview.data.data.grand_total) : "—"}</dd>
                            </div>
                        </dl>
                        <p className="mt-2 text-xs text-muted-foreground">
                            Số tiền dự kiến từ server; giá và tồn sẽ được kiểm tra lại khi tạo đơn.
                        </p>
                    </div>
                </section>

                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        className={secondaryButtonClass}
                        disabled={busy}
                        onClick={() => void submit(false)}
                    >
                        {busy ? "Đang xử lý..." : "Lưu bản nháp"}
                    </button>
                    <button
                        type="button"
                        className={buttonClass}
                        disabled={busy}
                        onClick={() => void submit(true)}
                    >
                        {busy ? "Đang xử lý..." : "Tạo đơn hàng"}
                    </button>
                </div>
            </div>

            <Dialog open={productOpen} onOpenChange={setProductOpen}>
                <DialogContent className="max-h-[90vh] w-[calc(100%-2rem)] max-w-5xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Thêm sản phẩm</DialogTitle>
                        <DialogDescription>
                            Giá Retail và tồn khả dụng tại{" "}
                            {selectedWarehouse?.name ?? "kho đã chọn"} được lấy từ server.
                        </DialogDescription>
                    </DialogHeader>
                    <input
                        className={fieldClass}
                        value={productSearch}
                        onChange={(event) => {
                            setProductSearch(event.target.value);
                            setProductPage(1);
                        }}
                        placeholder="Tìm tên sản phẩm hoặc SKU..."
                        aria-label="Tìm sản phẩm"
                    />
                    {catalog.isPending && <p className="text-sm">Đang tải sản phẩm...</p>}
                    {catalog.isError && (
                        <p role="alert" className="text-sm text-red-700">
                            {errorMessage(catalog.error)}
                        </p>
                    )}
                    {catalog.data?.data.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Không tìm thấy sản phẩm phù hợp.
                        </p>
                    )}
                    <div className="max-h-[55vh] overflow-y-auto">
                        {catalog.data?.data.map((row) => {
                            const unavailable =
                                !row.sellable ||
                                row.status !== "active" ||
                                row.product_status !== "active" ||
                                !row.track_inventory ||
                                row.unit_price === null;
                            const reason =
                                !row.sellable ||
                                row.status !== "active" ||
                                row.product_status !== "active"
                                    ? "Không bán Retail"
                                    : !row.track_inventory
                                      ? "Chưa theo dõi tồn kho"
                                      : row.unit_price === null
                                        ? "Chưa có giá Retail"
                                        : Number(row.available_quantity) <= 0
                                          ? "Hết hàng tại kho"
                                          : "";
                            return (
                                <div
                                    key={row.variant_id}
                                    className="grid gap-2 border-b py-3 text-sm sm:grid-cols-[48px_1fr_120px_110px_auto] sm:items-center"
                                >
                                    {row.product_image_url ? (
                                        <img
                                            src={row.product_image_url}
                                            alt=""
                                            className="h-12 w-12 rounded object-cover"
                                        />
                                    ) : (
                                        <div className="h-12 w-12 rounded bg-muted" />
                                    )}
                                    <div>
                                        <strong>
                                            {row.product_name} · {row.variant_name}
                                        </strong>
                                        <span className="block font-mono text-xs text-muted-foreground">
                                            {row.sku}
                                        </span>
                                        {reason && (
                                            <span className="block text-xs text-amber-700">
                                                {reason}
                                            </span>
                                        )}
                                    </div>
                                    <span>
                                        {row.unit_price === null ? "—" : money(row.unit_price)}
                                    </span>
                                    <span>
                                        Còn {formatProductQuantity(row.available_quantity ?? 0)}{" "}
                                        {row.unit_symbol ?? ""}
                                    </span>
                                    <button
                                        type="button"
                                        className={secondaryButtonClass}
                                        disabled={unavailable}
                                        onClick={() => addProduct(row)}
                                    >
                                        Thêm
                                    </button>
                                </div>
                            );
                        })}
                    </div>
                    {catalog.data && (
                        <Pagination
                            current={catalog.data.current_page}
                            last={catalog.data.last_page}
                            onPage={setProductPage}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={newBuyerOpen}
                onOpenChange={(open) => !buyerBusy && setNewBuyerOpen(open)}
            >
                <DialogContent className="w-[calc(100%-2rem)] max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Tạo khách hàng mới</DialogTitle>
                        <DialogDescription>
                            Nhập thông tin liên hệ để tạo hồ sơ khách mua hàng.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void createBuyer();
                        }}
                    >
                        {(
                            [
                                ["name", "Tên khách hàng *"],
                                ["phone", "Số điện thoại *"],
                                ["email", "Email *"],
                            ] as const
                        ).map(([name, label]) => (
                            <label key={name} className="grid gap-1 text-sm">
                                <span>{label}</span>
                                <input
                                    className={fieldClass}
                                    type={name === "email" ? "email" : "text"}
                                    value={newBuyer[name]}
                                    onChange={(event) =>
                                        setNewBuyer((current) => ({
                                            ...current,
                                            [name]: event.target.value,
                                        }))
                                    }
                                />
                            </label>
                        ))}
                        <p className="text-xs text-muted-foreground">
                            Khách có thể thiết lập mật khẩu qua chức năng Quên mật khẩu khi cần đăng
                            nhập.
                        </p>
                        <DialogFooter className="gap-2">
                            <button
                                type="button"
                                className={secondaryButtonClass}
                                disabled={buyerBusy}
                                onClick={() => setNewBuyerOpen(false)}
                            >
                                Hủy
                            </button>
                            <button type="submit" className={buttonClass} disabled={buyerBusy}>
                                {buyerBusy ? "Đang tạo..." : "Tạo khách hàng"}
                            </button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ProductAdminGuard>
    );
}
