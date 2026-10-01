import { useEffect, useMemo, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, Navigate, useNavigate } from "@tanstack/react-router";
import { CheckCircle2 } from "lucide-react";
import { toast } from "sonner";
import { EmptyState, ErrorState, LoadingState } from "@/components/common/AsyncState";
import { SalesOrderStatusBadge } from "@/components/common/SalesOrderStatusBadge";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { useAuth } from "@/contexts/AuthContext";
import { formatProductQuantity, isPositiveProductQuantity } from "@/lib/productQuantity";
import { ApiError, errorMessage } from "@/services/api";
import { commerceCodeMessage } from "@/services/commerceErrors";
import { dealerApi, dealerKeys } from "./api";
import { dealerOrderMainStatuses } from "./dealerOrderStatus";
import { QuickOrderProductPicker } from "./QuickOrderProductPicker";
import { DealerProductThumbnail } from "./DealerProductThumbnail";
import { DealerShippingAddressSelector } from "./DealerShippingAddressSelector";
import { appendUniqueRows, catalogRow, reviewRow, rowQuantityError } from "./quickOrderSelection";
import type { QuickOrderRow } from "./quickOrderSelection";
import { emptyDealerShippingForm } from "./types";
import type {
    DealerOrder,
    DealerQuickOrderReview,
    DealerRecipient,
    DealerShippingFormValue,
} from "./types";

const money = (value: string | null) =>
    value === null
        ? "—"
        : new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(
              Number(value),
          );

const validPhone = (value: string) => /^(?=.*[0-9])[+0-9().\-\s]+$/.test(value);

function shippingFieldErrors(
    error: unknown,
): Partial<Record<keyof DealerShippingFormValue, string>> {
    if (!(error instanceof ApiError)) return {};
    const fields: Partial<Record<keyof DealerShippingFormValue, string>> = {};
    const mapping = {
        recipient_name: "recipient_name",
        recipient_phone: "recipient_phone",
        shipping_province_code: "province_code",
        shipping_ward_code: "ward_code",
        shipping_address_line1: "address_line",
        shipping_district: "shipping_district",
    } as const;
    for (const [field, destination] of Object.entries(mapping)) {
        const message = error.errors[field]?.[0];
        if (message) fields[destination as keyof DealerShippingFormValue] = message;
    }
    return fields;
}

export function DealerQuickOrderPage({
    initialSearch = "",
    reorderId = 0,
}: {
    initialSearch?: string;
    reorderId?: number;
}) {
    const { user, isLoading } = useAuth();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [rows, setRows] = useState<QuickOrderRow[]>([]);
    const [shippingForm, setShippingForm] =
        useState<DealerShippingFormValue>(emptyDealerShippingForm);
    const [shippingErrors, setShippingErrors] = useState<
        Partial<Record<keyof DealerShippingFormValue, string>>
    >({});
    const [reviewOpen, setReviewOpen] = useState(false);
    const [reviewStep, setReviewStep] = useState<"address" | "summary">("address");
    const reviewDialogRef = useRef<HTMLDivElement>(null);
    const rowsRef = useRef<QuickOrderRow[]>([]);
    const [review, setReview] = useState<DealerQuickOrderReview | null>(null);
    const [recipient, setRecipient] = useState<DealerRecipient | null>(null);
    const [notice, setNotice] = useState("");
    const [busy, setBusy] = useState("");
    const [successOrder, setSuccessOrder] = useState<DealerOrder | null>(null);
    const [successOpen, setSuccessOpen] = useState(false);
    const [pickerReset, setPickerReset] = useState(0);
    const actionPending = useRef(false);
    const operationKey = useRef(crypto.randomUUID());
    const loadedReorder = useRef(0);
    useEffect(() => {
        rowsRef.current = rows;
    }, [rows]);
    useEffect(() => {
        if (reviewOpen) reviewDialogRef.current?.scrollTo(0, 0);
    }, [reviewOpen, reviewStep]);
    const accounts = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled: user?.role === "customer",
    });
    const selected = accounts.data?.data[0];
    const shippingSelection = useMemo<Partial<DealerRecipient> | null>(() => {
        if (
            !shippingForm.recipient_name.trim() ||
            !validPhone(shippingForm.recipient_phone) ||
            !shippingForm.province_code ||
            !shippingForm.ward_code ||
            !shippingForm.address_line.trim()
        )
            return null;
        return {
            recipient_name: shippingForm.recipient_name.trim(),
            recipient_phone: shippingForm.recipient_phone.trim(),
            shipping_province_code: shippingForm.province_code,
            shipping_ward_code: shippingForm.ward_code,
            shipping_district: shippingForm.shipping_district.trim() || null,
            shipping_address_line1: shippingForm.address_line.trim(),
        };
    }, [shippingForm]);
    const changeShippingForm = (next: DealerShippingFormValue) => {
        setShippingForm(next);
        setReviewStep("address");
        setShippingErrors({});
        setReview(null);
        setRecipient(null);
        setNotice("");
        operationKey.current = crypto.randomUUID();
    };
    const tier = useQuery({
        queryKey: dealerKeys.tier(user?.id, selected?.id ?? 0),
        queryFn: () => dealerApi.tier(selected!.id),
        enabled: Boolean(selected),
    });
    const reorder = useQuery({
        queryKey: dealerKeys.order(user?.id, selected?.id ?? 0, reorderId),
        queryFn: () => dealerApi.order(selected!.id, reorderId),
        enabled: Boolean(selected) && reorderId > 0,
        retry: false,
    });
    const recent = useQuery({
        queryKey: ["dealer-recent-products", user?.id, selected?.id],
        queryFn: async () => {
            const list = await dealerApi.orders(selected!.id, 1);
            const details = await Promise.allSettled(
                list.data.slice(0, 3).map((order) => dealerApi.order(selected!.id, order.id)),
            );
            if (details.length > 0 && details.every((result) => result.status === "rejected")) {
                throw new Error("Không thể tải lịch sử sản phẩm đã mua.");
            }
            const seen = new Set<string>();
            return details
                .flatMap((result) =>
                    result.status === "fulfilled"
                        ? (result.value.data.items ?? []).filter((item) => {
                              if (item.is_gift || seen.has(item.sku)) return false;
                              seen.add(item.sku);
                              return true;
                          })
                        : [],
                )
                .slice(0, 5);
        },
        enabled: Boolean(selected),
        retry: false,
    });
    const invalidate = () => {
        setReview(null);
        setReviewOpen(false);
        setNotice("");
        operationKey.current = crypto.randomUUID();
    };
    const changeQuantity = (variantId: number, quantity: string) => {
        setRows((current) =>
            current.map((item) =>
                item.product_variant_id === variantId
                    ? {
                          ...item,
                          quantity,
                          errors: item.errors.filter(
                              (code) =>
                                  ![
                                      "INVALID_QUANTITY",
                                      "DEALER_MOQ_NOT_MET",
                                      "INSUFFICIENT_STOCK",
                                  ].includes(code),
                          ),
                      }
                    : item,
            ),
        );
        invalidate();
    };
    const add = (row: QuickOrderRow) => {
        const result = appendUniqueRows(rowsRef.current, [row]);
        if (result.added === 0 && !result.exceedsLimit) {
            toast.info("Sản phẩm đã có trong đơn. Bạn có thể chỉnh số lượng bên dưới.");
            return;
        }
        if (result.exceedsLimit) {
            setNotice("Tối đa 50 SKU mỗi đơn.");
            toast.error("Tối đa 50 sản phẩm mỗi đơn.");
            return;
        }
        rowsRef.current = result.rows;
        setRows(result.rows);
        invalidate();
        toast.success("Đã thêm sản phẩm vào đơn.");
    };
    const addMany = (items: QuickOrderRow[]): number => {
        const result = appendUniqueRows(rowsRef.current, items);
        if (result.exceedsLimit) {
            toast.error("Tối đa 50 sản phẩm mỗi đơn.");
            return 0;
        }
        if (result.added === 0) {
            toast.info("Các sản phẩm này đã có trong đơn.");
            return 0;
        }
        rowsRef.current = result.rows;
        setRows(result.rows);
        invalidate();
        toast.success(`Đã thêm ${result.added} sản phẩm vào đơn.`);
        return result.added;
    };
    const payload = () =>
        rows.map(({ product_variant_id, quantity }) => ({ product_variant_id, quantity }));
    const refreshReview = async () => {
        if (!selected || rows.length === 0 || !shippingSelection) return;
        const response = await dealerApi.quickOrderReview(
            selected.id,
            payload(),
            shippingSelection,
        );
        setReview(response.data);
        setRows((current) =>
            response.data.items.map((line) =>
                reviewRow(
                    line,
                    current.find((row) => row.product_variant_id === line.product_variant_id),
                ),
            ),
        );
        setRecipient((current) => ({
            ...response.data.recipient_defaults,
            delivery_note: current?.delivery_note ?? null,
        }));
        return response.data;
    };
    const addRecent = async (sku: string) => {
        if (!selected || busy) return;
        setBusy("recent");
        try {
            const catalog = await dealerApi.products(selected.id, {
                search: sku,
                page: 1,
            });
            const product = catalog.data.find((entry) =>
                entry.variants.some((variant) => variant.sku === sku),
            );
            const variant = product?.variants.find((entry) => entry.sku === sku);
            if (!product || !variant) {
                setNotice(`SKU ${sku} không còn bán cho đại lý hoặc chưa có giá tier hiện tại.`);
                toast.error("Sản phẩm đã mua không còn khả dụng để đặt lại.");
                return;
            }
            add(catalogRow(product, variant));
        } catch (error) {
            setNotice(errorMessage(error));
            toast.error("Không thể tải sản phẩm đã mua gần đây.");
        } finally {
            setBusy("");
        }
    };
    useEffect(() => {
        if (!reorder.data || reorderId <= 0 || loadedReorder.current === reorderId) return;
        loadedReorder.current = reorderId;
        const oldOrder: DealerOrder = reorder.data.data;
        const oldItems = (oldOrder.items ?? []).filter(
            (item) => !item.is_gift && item.product_variant_id !== null,
        );
        if (oldItems.length === 0) {
            setNotice("Đơn cũ không có sản phẩm có thể đặt lại.");
            return;
        }
        const nextRows: QuickOrderRow[] = oldItems.map((item) => ({
            product_variant_id: item.product_variant_id!,
            sku: item.sku,
            product_name: item.product_name,
            variant_name: item.variant_name,
            image_url: null,
            quantity: formatProductQuantity(item.quantity),
            minimum_quantity: "1",
            unit_price: null,
            unit_symbol: item.unit_code,
            available_quantity: null,
            errors: [],
        }));
        rowsRef.current = nextRows;
        setRows(nextRows);
        setNotice(
            "Đã đưa sản phẩm từ đơn cũ vào đơn mới. Hãy xem lại số lượng và nhập địa chỉ trong bước xác nhận.",
        );
        toast.success("Đã tải lại sản phẩm từ đơn cũ.");
    }, [reorder.data, reorderId]);
    const startReview = () => {
        if (actionPending.current || busy || !selected) return;
        if (rows.some((row) => rowQuantityError(row))) {
            setNotice("Có số lượng chưa hợp lệ. Hãy kiểm tra các sản phẩm đã chọn.");
            return;
        }
        setReviewStep("address");
        setReviewOpen(true);
    };
    const reviewDelivery = async () => {
        if (actionPending.current || busy || !selected) return;
        const errors: Partial<Record<keyof DealerShippingFormValue, string>> = {};
        if (!shippingForm.recipient_name.trim())
            errors.recipient_name = "Vui lòng nhập họ tên người nhận.";
        if (!shippingForm.recipient_phone.trim())
            errors.recipient_phone = "Vui lòng nhập số điện thoại.";
        else if (!validPhone(shippingForm.recipient_phone))
            errors.recipient_phone = "Số điện thoại không hợp lệ.";
        if (!shippingForm.province_code) errors.province_code = "Vui lòng chọn Tỉnh / Thành phố.";
        if (!shippingForm.ward_code) errors.ward_code = "Vui lòng chọn Phường / Xã.";
        if (!shippingForm.address_line.trim())
            errors.address_line = "Vui lòng nhập địa chỉ chi tiết.";
        if (Object.keys(errors).length > 0 || !shippingSelection) {
            setShippingErrors(errors);
            return;
        }
        actionPending.current = true;
        setBusy("review");
        setNotice("");
        try {
            const nextReview = await refreshReview();
            setShippingErrors({});
            if (nextReview) setReviewStep("summary");
        } catch (error) {
            setShippingErrors(shippingFieldErrors(error));
            setNotice(errorMessage(error));
            toast.error("Không thể kiểm tra đơn hàng. Vui lòng thử lại.");
        } finally {
            actionPending.current = false;
            setBusy("");
        }
    };
    const submit = async () => {
        if (
            actionPending.current ||
            busy ||
            successOrder ||
            !selected ||
            !review?.can_submit ||
            !recipient
        )
            return;
        if (rows.some((row) => !isPositiveProductQuantity(row.quantity))) {
            setNotice("Số lượng mỗi SKU phải là số nguyên dương.");
            return;
        }
        actionPending.current = true;
        setBusy("submit");
        setNotice("");
        try {
            const response = await dealerApi.quickOrderSubmit(selected.id, {
                ...recipient,
                items: payload(),
                operation_key: operationKey.current,
                review_fingerprint: review.review_fingerprint,
                ...shippingSelection,
                save_address: shippingForm.save_address,
            });
            if (shippingForm.save_address) {
                void queryClient.invalidateQueries({
                    queryKey: ["dealer-shipping-addresses", selected.id],
                });
                toast.success("Đã lưu địa chỉ để dùng cho lần sau.");
            }
            void queryClient.invalidateQueries({
                queryKey: dealerKeys.wallet(user?.id, selected.id),
            });
            void queryClient.invalidateQueries({
                queryKey: dealerKeys.tier(user?.id, selected.id),
            });
            void queryClient.invalidateQueries({
                queryKey: ["dealer-orders", user?.id, selected.id],
            });
            void queryClient.invalidateQueries({
                queryKey: ["dealer-recent-products", user?.id, selected.id],
            });
            queryClient.setQueryData(
                dealerKeys.order(user?.id, selected.id, response.data.id),
                response,
            );
            setSuccessOrder(response.data);
            setReviewOpen(false);
            setSuccessOpen(true);
        } catch (error) {
            setShippingErrors(shippingFieldErrors(error));
            if (error instanceof ApiError && error.code === "DEALER_ORDER_CHANGED") {
                try {
                    await refreshReview();
                    setNotice("Giá, Tier hoặc dữ liệu đơn đã thay đổi. Hãy xem lại trước khi gửi.");
                } catch (refreshError) {
                    setReview(null);
                    setReviewStep("address");
                    setNotice(errorMessage(refreshError));
                }
                operationKey.current = crypto.randomUUID();
            } else if (error instanceof ApiError && error.code === "INSUFFICIENT_STOCK") {
                try {
                    await refreshReview();
                } catch {
                    setReview(null);
                    setReviewStep("address");
                }
                setNotice("Số lượng hiện không đủ để giữ hàng. Hãy kiểm tra lại đơn.");
            } else setNotice(errorMessage(error));
            toast.error("Không thể gửi đơn hàng. Vui lòng kiểm tra thông báo bên dưới.");
        } finally {
            actionPending.current = false;
            setBusy("");
        }
    };
    const createAnother = async () => {
        await navigate({
            to: "/dealer/quick-order",
            search: { sku: "", reorder: 0 },
            replace: true,
        });
        rowsRef.current = [];
        setRows([]);
        setReview(null);
        setRecipient(null);
        setReviewOpen(false);
        setReviewStep("address");
        setShippingForm(emptyDealerShippingForm);
        setShippingErrors({});
        setNotice("");
        setBusy("");
        setSuccessOpen(false);
        setSuccessOrder(null);
        setPickerReset((value) => value + 1);
        operationKey.current = crypto.randomUUID();
        loadedReorder.current = 0;
    };
    if (isLoading) return <LoadingState />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== "customer") return <Navigate to="/account" />;
    if (accounts.isPending) return <LoadingState />;
    if (accounts.isError)
        return (
            <ErrorState
                message={errorMessage(accounts.error)}
                retry={() => void accounts.refetch()}
            />
        );
    if (!selected) return <EmptyState message="Bạn chưa có tài khoản đại lý đang hoạt động." />;
    const estimatedTotal = rows.reduce(
        (total, row) =>
            total +
            (isPositiveProductQuantity(row.quantity)
                ? Number(row.unit_price ?? 0) * Number(row.quantity)
                : 0),
        0,
    );
    const validRows = rows.length > 0 && rows.every((row) => !rowQuantityError(row));
    return (
        <main className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p className="label-luxury">Junie B2B</p>
                    <h1 className="mt-2 text-3xl text-primary">Đặt hàng nhanh</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Tìm sản phẩm, kiểm tra giá đại lý và MOQ trước khi gửi đơn.
                    </p>
                </div>
                <Link to="/dealer/orders" className="text-sm font-medium text-primary underline">
                    Đơn hàng đại lý
                </Link>
            </header>
            {successOrder ? (
                <section
                    role="status"
                    className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm shadow-sm"
                >
                    <div>
                        <strong className="text-emerald-900">
                            Đơn {successOrder.order_code} đã được tạo.
                        </strong>
                        <p className="mt-1 text-emerald-800">
                            Bạn có thể xem chi tiết đơn hoặc bắt đầu đơn mới.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="rounded-md border border-primary px-3 py-2 font-medium text-primary"
                            onClick={() =>
                                void navigate({
                                    to: "/dealer/orders/$orderId",
                                    params: { orderId: String(successOrder.id) },
                                })
                            }
                        >
                            Xem đơn hàng
                        </button>
                        <button
                            type="button"
                            className="rounded-md bg-primary px-3 py-2 font-medium text-primary-foreground"
                            onClick={() => void createAnother()}
                        >
                            Tạo đơn khác
                        </button>
                    </div>
                </section>
            ) : (
                <>
                    {(reorderId === 0 || (!reorder.isPending && busy !== "reorder")) && (
                        <QuickOrderProductPicker
                            key={pickerReset}
                            accountId={selected.id}
                            userId={user?.id}
                            tierName={tier.data?.data.effective_tier?.name ?? "Giá đại lý"}
                            selectedIds={rows.map((row) => row.product_variant_id)}
                            onAdd={add}
                            onAddMany={addMany}
                            initialSearch={initialSearch}
                        />
                    )}
                    {recent.data && recent.data.length > 0 && (
                        <section className="space-y-2 text-sm">
                            <h2 className="font-medium text-primary">Đã mua gần đây</h2>
                            <div className="flex flex-wrap gap-2">
                                {recent.data.map((item) => (
                                    <button
                                        key={item.sku}
                                        type="button"
                                        disabled={Boolean(busy)}
                                        className="rounded-full border bg-card px-3 py-1.5 text-left hover:bg-accent disabled:opacity-50"
                                        onClick={() => void addRecent(item.sku)}
                                        title={`Thêm ${item.product_name} · ${item.variant_name} · ${item.sku}`}
                                    >
                                        {item.product_name} · {item.variant_name}
                                    </button>
                                ))}
                            </div>
                        </section>
                    )}
                    {recent.isError && (
                        <div className="flex items-center gap-3 text-sm text-muted-foreground">
                            <span>Không thể tải sản phẩm đã mua gần đây.</span>
                            <button
                                type="button"
                                className="text-primary underline"
                                onClick={() => void recent.refetch()}
                            >
                                Thử lại
                            </button>
                        </div>
                    )}
                    {busy === "reorder" && (
                        <p className="text-sm text-muted-foreground">
                            Đang kiểm tra lại giá, MOQ và tồn kho của đơn cũ...
                        </p>
                    )}
                    {reorderId > 0 &&
                        loadedReorder.current === 0 &&
                        reorder.data &&
                        busy !== "reorder" && (
                            <button
                                type="button"
                                className="text-sm text-primary underline"
                                onClick={() => void reorder.refetch()}
                            >
                                Thử đặt lại
                            </button>
                        )}
                    {reorderId > 0 &&
                        (reorder.isPending ? (
                            <p className="text-sm text-muted-foreground">
                                Đang tải sản phẩm của đơn cũ...
                            </p>
                        ) : reorder.isError ? (
                            <ErrorState
                                message={errorMessage(reorder.error)}
                                retry={() => void reorder.refetch()}
                            />
                        ) : null)}
                    <section className="space-y-4 rounded-xl border bg-card p-4 sm:p-6">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-xl text-primary">Sản phẩm đã chọn</h2>
                            <span className="text-sm text-muted-foreground">
                                {rows.length} sản phẩm
                            </span>
                        </div>
                        {rows.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Chưa có sản phẩm nào.</p>
                        ) : (
                            <div className="space-y-3">
                                {rows.map((row) => (
                                    <div
                                        key={row.product_variant_id}
                                        className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center"
                                    >
                                        <DealerProductThumbnail
                                            src={row.image_url}
                                            name={row.product_name}
                                        />
                                        <div className="min-w-0 text-sm">
                                            <strong className="block text-base text-primary">
                                                {row.product_name}
                                            </strong>
                                            <span>{row.variant_name}</span>
                                            <span className="block text-xs text-muted-foreground">
                                                SKU: {row.sku}
                                            </span>
                                            <span className="block">
                                                Giá{" "}
                                                {tier.data?.data.effective_tier?.name ?? "đại lý"}:{" "}
                                                {money(row.unit_price)}
                                            </span>
                                            {row.unit_price !== null && (
                                                <span className="block text-xs text-muted-foreground">
                                                    MOQ{" "}
                                                    {formatProductQuantity(row.minimum_quantity)}{" "}
                                                    {row.unit_symbol}
                                                </span>
                                            )}
                                            {row.available_quantity !== null && (
                                                <span className="block text-xs text-muted-foreground">
                                                    Khả dụng lúc kiểm tra:{" "}
                                                    {formatProductQuantity(row.available_quantity)}
                                                </span>
                                            )}
                                            {row.errors.map((code) => (
                                                <p
                                                    key={code}
                                                    role="alert"
                                                    className="text-xs text-red-700"
                                                >
                                                    {commerceCodeMessage(code, row.sku, {
                                                        available: row.available_quantity,
                                                        requested: row.quantity,
                                                        minimum: row.minimum_quantity,
                                                    })}
                                                </p>
                                            ))}
                                        </div>
                                        <div className="grid gap-2 text-sm sm:justify-items-end">
                                            <label className="grid gap-1">
                                                Số lượng
                                                <span className="flex items-center">
                                                    <button
                                                        type="button"
                                                        aria-label={`Giảm số lượng ${row.product_name}`}
                                                        className="rounded-l-md border px-3 py-2"
                                                        disabled={
                                                            !isPositiveProductQuantity(
                                                                row.quantity,
                                                            ) ||
                                                            Number(row.quantity) <=
                                                                Number(row.minimum_quantity)
                                                        }
                                                        onClick={() => {
                                                            changeQuantity(
                                                                row.product_variant_id,
                                                                String(Number(row.quantity) - 1),
                                                            );
                                                        }}
                                                    >
                                                        −
                                                    </button>
                                                    <input
                                                        className="w-20 border-y bg-background px-2 py-2 text-center"
                                                        type="number"
                                                        min={Number(row.minimum_quantity)}
                                                        step={1}
                                                        value={row.quantity}
                                                        onChange={(event) => {
                                                            changeQuantity(
                                                                row.product_variant_id,
                                                                event.target.value,
                                                            );
                                                        }}
                                                        onKeyDown={(event) => {
                                                            if (event.key === "Enter") {
                                                                event.currentTarget.blur();
                                                                if (!rowQuantityError(row))
                                                                    toast.success(
                                                                        "Đã cập nhật số lượng.",
                                                                    );
                                                            }
                                                        }}
                                                    />
                                                    <button
                                                        type="button"
                                                        aria-label={`Tăng số lượng ${row.product_name}`}
                                                        className="rounded-r-md border px-3 py-2"
                                                        disabled={
                                                            !isPositiveProductQuantity(
                                                                row.quantity,
                                                            ) ||
                                                            (row.available_quantity !== null &&
                                                                Number(row.quantity) >=
                                                                    Number(row.available_quantity))
                                                        }
                                                        onClick={() => {
                                                            changeQuantity(
                                                                row.product_variant_id,
                                                                String(Number(row.quantity) + 1),
                                                            );
                                                        }}
                                                    >
                                                        +
                                                    </button>
                                                </span>
                                            </label>
                                            {rowQuantityError(row) && (
                                                <p role="alert" className="text-xs text-red-700">
                                                    {rowQuantityError(row)}
                                                </p>
                                            )}
                                            <strong>
                                                Thành tiền:{" "}
                                                {row.unit_price &&
                                                isPositiveProductQuantity(row.quantity)
                                                    ? money(
                                                          String(
                                                              Number(row.unit_price) *
                                                                  Number(row.quantity),
                                                          ),
                                                      )
                                                    : "—"}
                                            </strong>
                                            <button
                                                type="button"
                                                className="text-red-700 underline"
                                                onClick={() => {
                                                    setRows((current) =>
                                                        current.filter(
                                                            (item) =>
                                                                item.product_variant_id !==
                                                                row.product_variant_id,
                                                        ),
                                                    );
                                                    invalidate();
                                                    toast.success("Đã xóa sản phẩm khỏi đơn.");
                                                }}
                                            >
                                                Xóa
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                        <div className="grid gap-2 border-t pt-4 text-sm sm:grid-cols-2">
                            <div>
                                <p>{rows.length} dòng sản phẩm</p>
                                <p>
                                    Tổng số lượng:{" "}
                                    {rows.reduce(
                                        (sum, row) =>
                                            sum +
                                            (isPositiveProductQuantity(row.quantity)
                                                ? Number(row.quantity)
                                                : 0),
                                        0,
                                    )}
                                </p>
                            </div>
                            <p className="text-lg font-semibold sm:text-right">
                                Tạm tính: {money(String(estimatedTotal))}
                            </p>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Giá, ưu đãi, tồn kho và số dư ví được kiểm tra lại khi xem đơn.
                        </p>
                        <button
                            type="button"
                            className="rounded-md bg-primary px-5 py-2 text-primary-foreground disabled:opacity-50"
                            disabled={!validRows || Boolean(busy)}
                            onClick={startReview}
                        >
                            Xem lại đơn và nhập địa chỉ
                        </button>
                    </section>
                    {notice && (
                        <p
                            role="alert"
                            className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
                        >
                            {notice}
                        </p>
                    )}
                    <Dialog open={reviewOpen} onOpenChange={setReviewOpen}>
                        <DialogContent
                            ref={reviewDialogRef}
                            className="max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl overflow-y-auto rounded-xl"
                        >
                            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Bước {reviewStep === "address" ? "1" : "2"} / 2
                            </p>
                            <DialogHeader>
                                <DialogTitle className="text-xl text-primary">
                                    {reviewStep === "address"
                                        ? "Thông tin giao hàng"
                                        : "Xem lại đơn hàng"}
                                </DialogTitle>
                                <DialogDescription>
                                    {reviewStep === "address"
                                        ? "Nhập người nhận và địa chỉ để kiểm tra đơn hàng."
                                        : "Kiểm tra thông tin giao hàng, sản phẩm và số tiền trước khi gửi đơn."}
                                </DialogDescription>
                            </DialogHeader>
                            {reviewStep === "address" && (
                                <div className="space-y-4 motion-safe:animate-in motion-safe:slide-in-from-left-6 motion-safe:fade-in-0 motion-safe:duration-300">
                                    <DealerShippingAddressSelector
                                        accountId={selected.id}
                                        value={shippingForm}
                                        errors={shippingErrors}
                                        onChange={changeShippingForm}
                                    />
                                    <button
                                        type="button"
                                        className="w-full rounded-md bg-primary px-5 py-2 text-primary-foreground disabled:opacity-50"
                                        disabled={Boolean(busy)}
                                        onClick={() => void reviewDelivery()}
                                    >
                                        {busy === "review"
                                            ? "Đang kiểm tra..."
                                            : review
                                              ? "Kiểm tra lại kho"
                                              : "Kiểm tra kho và đơn hàng"}
                                    </button>
                                    {notice && (
                                        <p role="alert" className="text-sm text-red-700">
                                            {notice}
                                        </p>
                                    )}
                                </div>
                            )}
                            {reviewStep === "summary" && review && (
                                <section className="space-y-5 motion-safe:animate-in motion-safe:slide-in-from-right-6 motion-safe:fade-in-0 motion-safe:duration-300">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h3 className="font-semibold text-primary">
                                            Kiểm tra đơn cuối cùng
                                        </h3>
                                        {review.effective_tier && (
                                            <span className="rounded-full border px-3 py-1 text-xs">
                                                Giá đại lý · {review.effective_tier.name}
                                            </span>
                                        )}
                                    </div>
                                    <div className="space-y-2 rounded-lg border bg-card p-4 text-sm">
                                        <h4 className="font-semibold text-primary">
                                            Thông tin giao hàng
                                        </h4>
                                        <p>
                                            <strong>{recipient?.recipient_name}</strong> ·{" "}
                                            {recipient?.recipient_phone}
                                        </p>
                                        <p>
                                            {recipient?.shipping_address_line1},{" "}
                                            {recipient?.shipping_ward},{" "}
                                            {recipient?.shipping_district &&
                                                `${recipient.shipping_district}, `}
                                            {recipient?.shipping_province}
                                        </p>
                                        {shippingForm.save_address && (
                                            <p className="text-xs text-muted-foreground">
                                                Địa chỉ này sẽ được lưu sau khi đặt hàng thành công.
                                            </p>
                                        )}
                                    </div>
                                    <p
                                        className={`text-sm ${review.warehouse_sufficient ? "text-emerald-700" : "text-red-700"}`}
                                    >
                                        Tình trạng tồn:{" "}
                                        {review.warehouse_sufficient
                                            ? "Đủ hàng"
                                            : "Không đủ hàng cho đơn này"}
                                    </p>
                                    <div className="space-y-2">
                                        <h4 className="font-semibold text-primary">
                                            Sản phẩm ({review.items.length})
                                        </h4>
                                        {review.items.map((line) => (
                                            <div
                                                key={line.product_variant_id}
                                                className="grid gap-2 border-b py-3 text-sm sm:grid-cols-[1fr_auto_auto]"
                                            >
                                                <div>
                                                    <strong>{line.sku}</strong> ·{" "}
                                                    {line.product_name}
                                                    {line.variant_name && (
                                                        <p className="text-muted-foreground">
                                                            Quy cách: {line.variant_name}
                                                        </p>
                                                    )}
                                                    <p className="text-muted-foreground">
                                                        Số lượng:{" "}
                                                        {formatProductQuantity(line.quantity)}{" "}
                                                        {line.unit_symbol} · MOQ{" "}
                                                        {formatProductQuantity(
                                                            line.minimum_quantity,
                                                        )}
                                                    </p>
                                                    {line.errors.length > 0 && (
                                                        <ul className="text-red-700">
                                                            {line.errors.map((code) => (
                                                                <li key={code}>
                                                                    {commerceCodeMessage(
                                                                        code,
                                                                        line.sku ?? undefined,
                                                                        {
                                                                            available:
                                                                                line.available_quantity,
                                                                            requested:
                                                                                line.quantity,
                                                                            minimum:
                                                                                line.minimum_quantity,
                                                                        },
                                                                    )}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </div>
                                                <span>Đơn giá: {money(line.unit_price)}</span>
                                                <strong>
                                                    Thành tiền: {money(line.line_total)}
                                                </strong>
                                            </div>
                                        ))}
                                    </div>
                                    <div className="space-y-1 text-right text-sm">
                                        <p>Tạm tính: {money(review.subtotal)}</p>
                                        {review.promotion && (
                                            <p>
                                                Ưu đãi {review.promotion.name}:{" "}
                                                {review.promotion.discount_type === "buy_a_get_b"
                                                    ? "Quà tặng"
                                                    : `−${money(review.discount_total)}`}
                                            </p>
                                        )}
                                        {review.gift_item && (
                                            <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-left text-amber-900">
                                                Quà tặng: {review.gift_item.product_name} /{" "}
                                                {review.gift_item.variant_name} ×{" "}
                                                {formatProductQuantity(review.gift_item.quantity)} ·{" "}
                                                {money("0")}
                                            </p>
                                        )}
                                        {review.gift_unavailable_reason && (
                                            <p
                                                role="status"
                                                className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-left text-amber-900"
                                            >
                                                Không đủ hàng cho cả sản phẩm mua và quà tặng. Ưu
                                                đãi quà tặng chưa được áp dụng.
                                            </p>
                                        )}
                                        {review.promotion?.discount_type === "buy_a_get_b" &&
                                            !review.promotion.qualified && (
                                                <p className="text-amber-800">
                                                    Mua thêm{" "}
                                                    {formatProductQuantity(
                                                        review.promotion.remaining_buy_quantity,
                                                    )}{" "}
                                                    sản phẩm để nhận quà.
                                                </p>
                                            )}
                                        <p className="text-xl font-semibold">
                                            Tổng: {money(review.grand_total)}
                                        </p>
                                        <p className="text-muted-foreground">
                                            Thanh toán bằng ví đại lý
                                        </p>
                                    </div>
                                    {!review.wallet_sufficient && (
                                        <p role="alert" className="text-sm text-red-700">
                                            Ví đại lý còn thiếu {money(review.wallet_shortfall)}.
                                            Vui lòng nạp tiền trước khi gửi đơn.
                                        </p>
                                    )}
                                    <label className="grid gap-1 text-sm">
                                        Ghi chú giao hàng
                                        <textarea
                                            className="rounded-md border bg-background px-3 py-2"
                                            value={recipient?.delivery_note ?? ""}
                                            onChange={(event) =>
                                                setRecipient((current) =>
                                                    current
                                                        ? {
                                                              ...current,
                                                              delivery_note: event.target.value,
                                                          }
                                                        : current,
                                                )
                                            }
                                        />
                                    </label>
                                    {!review.can_submit && (
                                        <p className="text-sm text-red-700">
                                            Đơn chưa đủ điều kiện gửi. Hãy đóng cửa sổ để chỉnh sản
                                            phẩm hoặc nạp ví, rồi kiểm tra lại.
                                        </p>
                                    )}
                                    {notice && (
                                        <p role="alert" className="text-sm text-red-700">
                                            {notice}
                                        </p>
                                    )}
                                    <div className="flex flex-col-reverse gap-2 border-t pt-4 sm:flex-row sm:justify-between">
                                        <button
                                            type="button"
                                            className="rounded-md border px-5 py-2 text-primary hover:bg-accent disabled:opacity-50"
                                            disabled={Boolean(busy)}
                                            onClick={() => setReviewStep("address")}
                                        >
                                            Quay lại sửa địa chỉ
                                        </button>
                                        <button
                                            type="button"
                                            className="rounded-md bg-primary px-5 py-2 text-primary-foreground disabled:opacity-50"
                                            disabled={
                                                !review.can_submit ||
                                                Boolean(busy) ||
                                                !shippingSelection
                                            }
                                            onClick={() => void submit()}
                                        >
                                            {busy === "submit"
                                                ? "Đang gửi..."
                                                : "Xác nhận và gửi đơn"}
                                        </button>
                                    </div>
                                </section>
                            )}
                        </DialogContent>
                    </Dialog>
                </>
            )}
            <Dialog open={successOrder !== null && successOpen} onOpenChange={setSuccessOpen}>
                <DialogContent className="w-[calc(100%-2rem)] max-w-[34rem] rounded-xl border shadow-lg">
                    <DialogHeader className="items-center text-center sm:text-center">
                        <CheckCircle2
                            className="mb-2 size-12 text-emerald-600"
                            aria-hidden="true"
                        />
                        <DialogTitle className="text-2xl text-primary">
                            Đặt hàng thành công
                        </DialogTitle>
                        <DialogDescription>Đơn hàng của bạn đã được tạo.</DialogDescription>
                    </DialogHeader>
                    {successOrder && (
                        <div className="space-y-4 rounded-lg border bg-card p-4 text-sm">
                            <div className="grid gap-1">
                                <span className="text-muted-foreground">Mã đơn</span>
                                <strong className="text-base text-primary">
                                    {successOrder.order_code}
                                </strong>
                            </div>
                            <div className="grid gap-1 border-t pt-3">
                                <span className="text-muted-foreground">Tổng thanh toán</span>
                                <strong className="text-lg text-primary">
                                    {new Intl.NumberFormat("vi-VN").format(
                                        Number(successOrder.grand_total),
                                    )}{" "}
                                    đ
                                </strong>
                            </div>
                            <div className="grid gap-2 border-t pt-3">
                                <span className="text-muted-foreground">Trạng thái</span>
                                <div className="flex flex-wrap gap-2">
                                    {dealerOrderMainStatuses(successOrder).map((entry) => (
                                        <SalesOrderStatusBadge
                                            key={entry.kind}
                                            kind={entry.kind}
                                            status={entry.key}
                                        />
                                    ))}
                                </div>
                            </div>
                            <div className="grid gap-1 border-t pt-3">
                                <span className="text-muted-foreground">Kho giao hàng</span>
                                <strong>{successOrder.warehouse?.name ?? "Chưa xác định"}</strong>
                            </div>
                        </div>
                    )}
                    <DialogFooter className="grid gap-2 sm:grid-cols-2 sm:space-x-0">
                        <button
                            type="button"
                            className="w-full rounded-md bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                            onClick={() =>
                                successOrder &&
                                void navigate({
                                    to: "/dealer/orders/$orderId",
                                    params: { orderId: String(successOrder.id) },
                                })
                            }
                        >
                            Xem đơn hàng
                        </button>
                        <button
                            type="button"
                            className="w-full rounded-md border px-4 py-2.5 text-sm font-medium text-primary hover:bg-accent"
                            onClick={() => void createAnother()}
                        >
                            Tạo đơn khác
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </main>
    );
}
