import { useEffect, useRef, useState, type FormEvent, type ReactNode } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, Navigate, useNavigate } from "@tanstack/react-router";
import { ShoppingBag, Trash2 } from "lucide-react";
import { Container } from "@/components/common/Container";
import { CustomerReturnSection } from "@/components/CustomerReturnSection";
import { Button, ButtonLink } from "@/components/common/Button";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { useAuth } from "@/contexts/AuthContext";
import { formatProductQuantity, isPositiveProductQuantity } from "@/lib/productQuantity";
import { ApiError, firstFieldErrors } from "@/services/api";
import { commerceCodeMessage } from "@/services/commerceErrors";
import { retailCommerceApi, retailErrorMessage, retailKeys } from "@/services/retailCommerceApi";
import type { RecipientForm, RetailCartLine, RetailOrder } from "@/types/retailCommerce";
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

const money = (value: string) =>
    new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(Number(value));
const orderStates: Record<string, string> = {
    pending: "Chờ xác nhận",
    confirmed: "Đã xác nhận",
    preparing: "Đang chuẩn bị hàng",
    shipping: "Đang giao",
    delivered: "Đã giao",
    processing: "Đang xử lý",
    completed: "Hoàn tất",
    cancelled: "Đã hủy",
    unpaid: "Chưa thanh toán",
    partially_paid: "Thanh toán một phần",
    paid: "Đã thanh toán",
    reserved: "Đã giữ hàng",
    partially_fulfilled: "Đã giao một phần",
    fulfilled: "Đã giao",
    unfulfilled: "Chưa giao",
};

function RetailGuard({ children, returnTo }: { children: ReactNode; returnTo: string }) {
    const { user, isLoading } = useAuth();
    if (isLoading)
        return (
            <Container className="py-16">
                <LoadingState />
            </Container>
        );
    if (!user) return <Navigate to="/login" search={{ returnTo }} />;
    return <>{children}</>;
}

function PageHeading({ eyebrow, title }: { eyebrow: string; title: string }) {
    return (
        <div className="mb-8">
            <p className="label-luxury">{eyebrow}</p>
            <h1 className="mt-3 text-3xl text-primary md:text-4xl">{title}</h1>
        </div>
    );
}

function CartLine({
    line,
    onUpdate,
    onRemove,
    busy,
}: {
    line: RetailCartLine;
    onUpdate: (id: number, quantity: string) => void;
    onRemove: (id: number) => void;
    busy: boolean;
}) {
    const [quantity, setQuantity] = useState(formatProductQuantity(line.quantity));
    useEffect(() => setQuantity(formatProductQuantity(line.quantity)), [line.quantity]);
    return (
        <article className="grid gap-4 rounded-xl border bg-card p-4 shadow-sm sm:grid-cols-[96px_minmax(0,1fr)] sm:p-5">
            <Link
                to="/products/$slug"
                params={{ slug: line.product_slug }}
                className="aspect-square overflow-hidden rounded-lg bg-muted"
            >
                {line.image_url && (
                    <img
                        src={line.image_url}
                        alt={line.product_name}
                        className="size-full object-cover"
                    />
                )}
            </Link>
            <div className="min-w-0">
                <Link
                    to="/products/$slug"
                    params={{ slug: line.product_slug }}
                    className="font-semibold text-primary hover:underline"
                >
                    {line.product_name}
                </Link>
                <p className="mt-1 text-sm text-muted-foreground">
                    {line.variant_name} · {line.sku} · {line.unit_name}
                </p>
                <p className="mt-2 font-medium text-primary">
                    {line.retail_price ? money(line.retail_price.unit_price) : "Chưa có giá"}
                </p>
                <div className="mt-3 flex flex-wrap items-end gap-2">
                    <label className="grid gap-1 text-sm">
                        <span>Số lượng</span>
                        <input
                            className="w-28 rounded-md border bg-background px-3 py-2"
                            type="number"
                            min={1}
                            step={1}
                            value={quantity}
                            onChange={(event) => setQuantity(event.target.value)}
                        />
                    </label>
                    <Button
                        variant="outline"
                        disabled={
                            busy ||
                            !isPositiveProductQuantity(quantity) ||
                            quantity === formatProductQuantity(line.quantity)
                        }
                        onClick={() => onUpdate(line.id, quantity)}
                    >
                        Cập nhật
                    </Button>
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() => onRemove(line.id)}
                        aria-label={`Xóa ${line.product_name}`}
                    >
                        <Trash2 size={16} /> Xóa
                    </Button>
                </div>
                {!isPositiveProductQuantity(quantity) && (
                    <p role="alert" className="mt-2 text-sm text-red-700">
                        Số lượng phải là số nguyên dương.
                    </p>
                )}
                {line.errors.length > 0 && (
                    <div role="alert" className="mt-3 text-sm text-red-700">
                        {line.errors.map((code) => (
                            <p key={code}>
                                {commerceCodeMessage(code, line.sku, {
                                    available: line.available_quantity,
                                    requested: line.quantity,
                                })}
                            </p>
                        ))}
                    </div>
                )}
                <p className="mt-3 text-sm text-muted-foreground">
                    Khả dụng:{" "}
                    {line.available_quantity === null
                        ? "Chưa có kho bán hàng"
                        : formatProductQuantity(line.available_quantity)}{" "}
                    {line.unit_symbol || line.unit_name}
                </p>
                <p className="mt-2 font-semibold text-primary">
                    Thành tiền: {line.line_total ? money(line.line_total) : "—"}
                </p>
            </div>
        </article>
    );
}

export function CartPage() {
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const [message, setMessage] = useState("");
    const [voucherCode, setVoucherCode] = useState("");
    const query = useQuery({
        queryKey: retailKeys.cart(user?.id),
        queryFn: retailCommerceApi.cart,
        enabled: Boolean(user),
    });
    const mutation = useMutation({
        mutationFn: async (action: {
            type: "update" | "remove" | "clear" | "voucher";
            id?: number;
            quantity?: string;
            code?: string | null;
        }) => {
            if (action.type === "update")
                return retailCommerceApi.update(action.id!, action.quantity!);
            if (action.type === "remove") return retailCommerceApi.remove(action.id!);
            if (action.type === "voucher") return retailCommerceApi.voucher(action.code ?? null);
            return retailCommerceApi.clear();
        },
        onSuccess: (result) => {
            queryClient.setQueryData(retailKeys.cart(user?.id), result);
            queryClient.invalidateQueries({ queryKey: retailKeys.review(user?.id) });
            setMessage("");
        },
        onError: (reason) => setMessage(retailErrorMessage(reason)),
    });
    const cart = query.data?.data;
    return (
        <RetailGuard returnTo="/cart">
            <Container className="py-12 md:py-16">
                <PageHeading eyebrow="Junie Retail" title="Giỏ hàng" />
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState
                        message={retailErrorMessage(query.error)}
                        retry={() => query.refetch()}
                    />
                ) : !cart || cart.item_count === 0 ? (
                    <div className="space-y-5">
                        <EmptyState message="Giỏ hàng của bạn đang trống." />
                        <ButtonLink to="/products">Tiếp tục mua sắm</ButtonLink>
                    </div>
                ) : (
                    <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_320px]">
                        <div className="grid gap-4">
                            {cart.items.map((line) => (
                                <CartLine
                                    key={line.id}
                                    line={line}
                                    busy={mutation.isPending}
                                    onUpdate={(id, quantity) =>
                                        mutation.mutate({ type: "update", id, quantity })
                                    }
                                    onRemove={(id) => mutation.mutate({ type: "remove", id })}
                                />
                            ))}
                            {cart.gift_item && (
                                <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm">
                                    <strong>Quà tặng · {cart.gift_item.product_name}</strong>
                                    <p>
                                        {cart.gift_item.variant_name} ×{" "}
                                        {formatProductQuantity(cart.gift_item.quantity)} ·{" "}
                                        {money("0")}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Số lượng quà được hệ thống tự tính.
                                    </p>
                                </div>
                            )}
                            {cart.gift_unavailable_reason && (
                                <p
                                    role="status"
                                    className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
                                >
                                    Đơn hiện không đủ tồn kho cho cả hàng mua và quà tặng. Quà tặng
                                    chưa được áp dụng.
                                </p>
                            )}
                            <Button
                                variant="ghost"
                                className="justify-self-start"
                                disabled={mutation.isPending}
                                onClick={() => mutation.mutate({ type: "clear" })}
                            >
                                Xóa giỏ hàng
                            </Button>
                        </div>
                        <aside className="h-fit rounded-xl border bg-card p-5 shadow-sm lg:sticky lg:top-28">
                            <h2 className="text-xl font-semibold text-primary">Tóm tắt</h2>
                            <p className="mt-4 flex justify-between gap-3 text-sm">
                                <span>Tạm tính</span>
                                <strong>{money(cart.subtotal)}</strong>
                            </p>
                            <p className="mt-2 flex justify-between gap-3 text-sm">
                                <span>Phí vận chuyển</span>
                                <span>{money(cart.shipping_total)}</span>
                            </p>
                            <form
                                className="mt-4 flex gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    mutation.mutate({ type: "voucher", code: voucherCode.trim() });
                                }}
                            >
                                <input
                                    aria-label="Mã voucher"
                                    className="min-w-0 flex-1 rounded-md border bg-background px-3 py-2 text-sm"
                                    placeholder="Mã voucher"
                                    value={voucherCode}
                                    onChange={(event) => setVoucherCode(event.target.value)}
                                />
                                <Button type="submit" disabled={mutation.isPending}>
                                    Áp dụng
                                </Button>
                            </form>
                            {cart.voucher_code && (
                                <p className="mt-2 text-sm">
                                    Voucher {cart.voucher_code}
                                    {cart.voucher_percent ? ` (${cart.voucher_percent}%)` : ""}{" "}
                                    <button
                                        type="button"
                                        className="underline"
                                        disabled={mutation.isPending}
                                        onClick={() =>
                                            mutation.mutate({ type: "voucher", code: null })
                                        }
                                    >
                                        Bỏ
                                    </button>
                                </p>
                            )}
                            {cart.voucher_error && (
                                <p role="alert" className="mt-2 text-sm text-red-700">
                                    {commerceCodeMessage(cart.voucher_error)}
                                </p>
                            )}
                            {cart.promotion?.discount_type === "buy_a_get_b" &&
                                !cart.promotion.qualified && (
                                    <p className="mt-2 text-sm text-amber-800">
                                        Mua thêm{" "}
                                        {formatProductQuantity(
                                            cart.promotion.remaining_buy_quantity,
                                        )}{" "}
                                        sản phẩm để nhận quà.
                                    </p>
                                )}
                            <p className="mt-2 flex justify-between gap-3 text-sm">
                                <span>Giảm giá</span>
                                <span>−{money(cart.discount_total)}</span>
                            </p>
                            <p className="mt-4 flex justify-between gap-3 border-t pt-4 font-semibold">
                                <span>Tổng cộng</span>
                                <span>{money(cart.grand_total)}</span>
                            </p>
                            <p className="mt-3 text-xs text-muted-foreground">
                                Giá và khả dụng được kiểm tra lại khi đặt hàng.
                            </p>
                            {cart.can_checkout ? (
                                <ButtonLink to="/checkout" className="mt-5 w-full">
                                    Tiến hành đặt hàng
                                </ButtonLink>
                            ) : (
                                <p className="mt-5 rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                                    Kiểm tra các dòng hàng và kho bán hàng trước khi đặt.
                                </p>
                            )}
                        </aside>
                    </div>
                )}
                {message && (
                    <p role="alert" className="mt-5 text-sm text-red-700">
                        {message}
                    </p>
                )}
            </Container>
        </RetailGuard>
    );
}

const emptyRecipient: RecipientForm = {
    recipient_name: "",
    recipient_phone: "",
    recipient_email: "",
    shipping_address_line1: "",
    shipping_address_line2: "",
    shipping_city: "",
    shipping_district: "",
    shipping_province: "",
    shipping_country: "VN",
    shipping_postal_code: "",
    delivery_note: "",
    payment_method: "cod",
};
const recipientFields: Array<{ key: keyof RecipientForm; label: string; required?: boolean }> = [
    { key: "recipient_name", label: "Tên người nhận", required: true },
    { key: "recipient_phone", label: "Số điện thoại", required: true },
    { key: "recipient_email", label: "Email" },
    { key: "shipping_address_line1", label: "Địa chỉ", required: true },
    { key: "shipping_address_line2", label: "Địa chỉ bổ sung" },
    { key: "shipping_city", label: "Thành phố", required: true },
    { key: "shipping_district", label: "Quận / huyện", required: true },
    { key: "shipping_province", label: "Tỉnh / Thành", required: true },
    { key: "shipping_country", label: "Quốc gia", required: true },
    { key: "shipping_postal_code", label: "Mã bưu chính" },
    { key: "delivery_note", label: "Ghi chú giao hàng" },
];

export function CheckoutPage() {
    const { user } = useAuth();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [form, setForm] = useState<RecipientForm>(emptyRecipient);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState("");
    const [confirmOpen, setConfirmOpen] = useState(false);
    const operationKey = useRef<string | null>(null);
    const review = useQuery({
        queryKey: retailKeys.review(user?.id),
        queryFn: retailCommerceApi.review,
        enabled: Boolean(user),
        retry: false,
    });
    useEffect(() => {
        const defaults = review.data?.data.recipient_defaults;
        if (defaults)
            setForm((previous) => ({
                ...previous,
                recipient_name: previous.recipient_name || defaults.recipient_name,
                recipient_phone: previous.recipient_phone || defaults.recipient_phone || "",
                recipient_email: previous.recipient_email || defaults.recipient_email || "",
            }));
    }, [review.data]);
    const mutation = useMutation({
        mutationFn: () =>
            retailCommerceApi.checkout({
                ...form,
                checkout_review_fingerprint: review.data!.data.review_fingerprint,
                checkout_operation_key: (operationKey.current ??= crypto.randomUUID()),
            }),
        onSuccess: async (result) => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: retailKeys.cart(user?.id) }),
                queryClient.invalidateQueries({ queryKey: ["retail-orders"] }),
            ]);
            await navigate({
                to: "/checkout/success/$orderId",
                params: { orderId: String(result.data.id) },
            });
        },
        onError: (reason) => {
            setFieldErrors(firstFieldErrors(reason));
            if (reason instanceof ApiError && reason.code === "CHECKOUT_CHANGED") {
                operationKey.current = null;
                setMessage(
                    "Giỏ hàng, giá hoặc kho bán hàng đã thay đổi. Vui lòng xem lại tổng tiền trước khi đặt.",
                );
                queryClient.invalidateQueries({ queryKey: retailKeys.review(user?.id) });
            } else if (reason instanceof ApiError && reason.code === "INSUFFICIENT_STOCK") {
                setMessage(
                    `Không đủ hàng cho SKU ${reason.details.sku ?? "đã chọn"}. Khả dụng: ${formatProductQuantity(reason.details.available ?? "0")}.`,
                );
                queryClient.invalidateQueries({ queryKey: retailKeys.review(user?.id) });
            } else {
                setMessage(retailErrorMessage(reason));
            }
        },
    });
    async function prepare(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setMessage("");
        setFieldErrors({});
        const previous = review.data?.data.review_fingerprint;
        const fresh = await review.refetch();
        if (fresh.isError) {
            setMessage(retailErrorMessage(fresh.error));
            return;
        }
        if (!fresh.data?.data.can_checkout || fresh.data.data.review_fingerprint !== previous) {
            setMessage("Thông tin đơn hàng đã thay đổi. Vui lòng xem lại trước khi đặt.");
            operationKey.current = null;
            return;
        }
        setConfirmOpen(true);
    }
    const cart = review.data?.data;
    return (
        <RetailGuard returnTo="/checkout">
            <Container className="py-12 md:py-16">
                <PageHeading eyebrow="Junie Retail" title="Xác nhận đặt hàng" />
                {review.isPending ? (
                    <LoadingState />
                ) : review.isError ? (
                    <div className="grid gap-4">
                        <ErrorState
                            message={retailErrorMessage(review.error)}
                            retry={() => review.refetch()}
                        />
                        <ButtonLink to="/cart" variant="outline">
                            Quay lại giỏ hàng
                        </ButtonLink>
                    </div>
                ) : !cart?.can_checkout ? (
                    <div className="grid gap-4">
                        <EmptyState message="Giỏ hàng chưa sẵn sàng để đặt hàng." />
                        <ButtonLink to="/cart">Kiểm tra giỏ hàng</ButtonLink>
                    </div>
                ) : (
                    <form
                        onSubmit={prepare}
                        className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_340px]"
                    >
                        <div className="rounded-xl border bg-card p-5 shadow-sm sm:p-7">
                            <h2 className="text-xl font-semibold text-primary">
                                Người nhận và địa chỉ giao hàng
                            </h2>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Người nhận có thể khác chủ tài khoản. Thông tin này được lưu theo
                                đơn hàng.
                            </p>
                            <div className="mt-6 grid gap-4 sm:grid-cols-2">
                                {recipientFields.map(({ key, label, required }) => (
                                    <label
                                        key={key}
                                        className={`grid gap-1 text-sm ${key === "shipping_address_line1" || key === "shipping_address_line2" || key === "delivery_note" ? "sm:col-span-2" : ""}`}
                                    >
                                        <span>
                                            {label}
                                            {required ? " *" : ""}
                                        </span>
                                        <input
                                            className="min-w-0 rounded-md border bg-background px-3 py-2"
                                            value={form[key]}
                                            required={required}
                                            type={key === "recipient_email" ? "email" : "text"}
                                            onChange={(event) =>
                                                setForm({ ...form, [key]: event.target.value })
                                            }
                                        />
                                        {fieldErrors[key] && (
                                            <span className="text-red-700">{fieldErrors[key]}</span>
                                        )}
                                    </label>
                                ))}
                            </div>
                            <label className="mt-5 grid gap-1 text-sm">
                                <span>Phương thức thanh toán *</span>
                                <select
                                    className="rounded-md border bg-background px-3 py-2"
                                    value={form.payment_method}
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            payment_method: event.target
                                                .value as RecipientForm["payment_method"],
                                        })
                                    }
                                >
                                    <option value="cod">Thanh toán khi nhận hàng (COD)</option>
                                    <option value="bank_transfer">Chuyển khoản ngân hàng</option>
                                </select>
                            </label>
                        </div>
                        <aside className="h-fit rounded-xl border bg-card p-5 shadow-sm lg:sticky lg:top-28">
                            <h2 className="text-xl font-semibold text-primary">
                                Kiểm tra đơn hàng
                            </h2>
                            <p className="mt-2 text-xs text-muted-foreground">
                                Kho bán hàng: {cart.warehouse?.name}
                            </p>
                            <div className="mt-5 grid gap-3">
                                {cart.items.map((line) => (
                                    <div
                                        key={line.id}
                                        className="flex justify-between gap-3 border-b pb-3 text-sm"
                                    >
                                        <span>
                                            {line.product_name}
                                            <span className="block text-xs text-muted-foreground">
                                                {line.variant_name} ×{" "}
                                                {formatProductQuantity(line.quantity)}
                                            </span>
                                        </span>
                                        <strong className="shrink-0">
                                            {money(line.line_total ?? "0")}
                                        </strong>
                                    </div>
                                ))}
                                {cart.gift_item && (
                                    <div className="flex justify-between gap-3 border-b pb-3 text-sm">
                                        <span>
                                            Quà tặng · {cart.gift_item.product_name}
                                            <span className="block text-xs">
                                                {cart.gift_item.variant_name} ×{" "}
                                                {formatProductQuantity(cart.gift_item.quantity)}
                                            </span>
                                        </span>
                                        <strong>{money("0")}</strong>
                                    </div>
                                )}
                            </div>
                            {cart.promotion && (
                                <p className="mt-3 flex justify-between gap-3 text-sm">
                                    <span>Ưu đãi {cart.promotion.name}</span>
                                    <span>
                                        {cart.promotion.discount_type === "buy_a_get_b"
                                            ? "Quà tặng"
                                            : `−${money(cart.promotion.discount_amount)}`}
                                    </span>
                                </p>
                            )}
                            {cart.voucher && (
                                <p className="mt-2 flex justify-between gap-3 text-sm">
                                    <span>Voucher {cart.voucher.code}</span>
                                    <span>−{money(cart.voucher.discount_amount)}</span>
                                </p>
                            )}
                            <p className="mt-4 flex justify-between gap-3 font-semibold">
                                <span>Tổng cộng</span>
                                <span>{money(cart.grand_total)}</span>
                            </p>
                            <p className="mt-3 text-xs text-muted-foreground">
                                {cart.gift_item
                                    ? "Tồn kho sản phẩm và quà được giữ khi đặt hàng."
                                    : "Đơn đang chờ Admin xác nhận; tồn kho được giữ sau khi xác nhận."}
                            </p>
                            <Button
                                type="submit"
                                disabled={mutation.isPending}
                                className="mt-5 w-full"
                            >
                                Đặt hàng
                            </Button>
                            <ButtonLink to="/cart" variant="ghost" className="mt-2 w-full">
                                Quay lại giỏ hàng
                            </ButtonLink>
                        </aside>
                    </form>
                )}
                {message && (
                    <p role="alert" className="mt-5 rounded-md bg-red-50 p-3 text-sm text-red-700">
                        {message}
                    </p>
                )}
                <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Xác nhận đặt hàng?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Tổng tiền {money(cart?.grand_total ?? "0")}. Đơn sẽ ở trạng thái chờ
                                xác nhận.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Kiểm tra lại</AlertDialogCancel>
                            <AlertDialogAction
                                disabled={mutation.isPending}
                                onClick={() => mutation.mutate()}
                            >
                                Xác nhận đặt hàng
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </Container>
        </RetailGuard>
    );
}

function OrderSummary({ order }: { order: RetailOrder }) {
    return (
        <article className="rounded-xl border bg-card p-5 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-semibold text-primary">{order.order_code}</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {new Date(order.created_at).toLocaleDateString("vi-VN")} ·{" "}
                        {order.item_count} mặt hàng
                    </p>
                </div>
                <strong className="text-primary">{money(order.grand_total)}</strong>
            </div>
            <div className="mt-4 flex flex-wrap gap-2 text-xs">
                <span className="rounded-full bg-muted px-3 py-1">
                    {orderStates[order.order_status] ?? order.order_status}
                </span>
                <span className="rounded-full bg-muted px-3 py-1">
                    {orderStates[order.payment_status] ?? order.payment_status}
                </span>
                <span className="rounded-full bg-muted px-3 py-1">
                    {orderStates[order.fulfillment_status] ?? order.fulfillment_status}
                </span>
            </div>
            <p className="mt-4 text-sm text-muted-foreground">Người nhận: {order.recipient_name}</p>
            <Link
                to="/my-orders/$orderId"
                params={{ orderId: String(order.id) }}
                className="mt-4 inline-block text-sm font-semibold text-primary underline"
            >
                Xem chi tiết
            </Link>
        </article>
    );
}

export function MyOrdersPage() {
    const { user } = useAuth();
    const [page, setPage] = useState(1);
    const query = useQuery({
        queryKey: retailKeys.orders(user?.id, page),
        queryFn: () => retailCommerceApi.orders(page),
        enabled: Boolean(user),
    });
    return (
        <RetailGuard returnTo="/my-orders">
            <Container className="py-12 md:py-16">
                <PageHeading eyebrow="Junie Retail" title="Đơn hàng của tôi" />
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState
                        message={retailErrorMessage(query.error)}
                        retry={() => query.refetch()}
                    />
                ) : query.data.data.length === 0 ? (
                    <div className="grid gap-5">
                        <EmptyState message="Bạn chưa có đơn hàng nào." />
                        <ButtonLink to="/products">Khám phá sản phẩm</ButtonLink>
                    </div>
                ) : (
                    <>
                        <div className="grid gap-4 md:grid-cols-2">
                            {query.data.data.map((order) => (
                                <OrderSummary key={order.id} order={order} />
                            ))}
                        </div>
                        <Pagination
                            current={query.data.meta.current_page}
                            last={query.data.meta.last_page}
                            onPage={setPage}
                        />
                    </>
                )}
            </Container>
        </RetailGuard>
    );
}

export function OrderDetailPage({ orderId }: { orderId: number }) {
    const { user } = useAuth();
    const query = useQuery({
        queryKey: retailKeys.order(user?.id, orderId),
        queryFn: () => retailCommerceApi.order(orderId),
        enabled: Boolean(user),
        retry: false,
    });
    const order = query.data?.data;
    return (
        <RetailGuard returnTo={`/my-orders/${orderId}`}>
            <Container className="py-12 md:py-16">
                <Link to="/my-orders" className="text-sm text-primary underline">
                    ← Đơn hàng của tôi
                </Link>
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <div className="mt-6">
                        <ErrorState
                            message={retailErrorMessage(query.error)}
                            retry={() => query.refetch()}
                        />
                    </div>
                ) : (
                    order && (
                        <>
                            <div className="mt-8">
                                <PageHeading eyebrow="Đơn hàng Retail" title={order.order_code} />
                            </div>
                            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                                <div className="grid gap-5">
                                    <div className="rounded-xl border bg-card p-5">
                                        <h2 className="text-xl font-semibold text-primary">
                                            Sản phẩm
                                        </h2>
                                        <div className="mt-4 divide-y">
                                            {order.items.map((item) => (
                                                <div
                                                    key={item.id}
                                                    className="grid gap-2 py-4 text-sm sm:grid-cols-[minmax(0,1fr)_auto]"
                                                >
                                                    <div>
                                                        {item.image_url && (
                                                            <img
                                                                src={item.image_url}
                                                                alt={item.product_name}
                                                                className="mb-2 h-16 w-16 rounded object-cover"
                                                            />
                                                        )}
                                                        <strong>{item.product_name}</strong>
                                                        {item.is_gift && (
                                                            <span className="ml-2 rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-900">
                                                                Quà tặng
                                                            </span>
                                                        )}
                                                        <p className="text-muted-foreground">
                                                            {item.variant_name} · {item.sku}
                                                        </p>
                                                        <p className="text-muted-foreground">
                                                            {formatProductQuantity(item.quantity)}{" "}
                                                            {item.unit_name} ×{" "}
                                                            {money(item.unit_price)}
                                                        </p>
                                                    </div>
                                                    <strong>{money(item.line_total)}</strong>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                    <div className="rounded-xl border bg-card p-5">
                                        <h2 className="text-xl font-semibold text-primary">
                                            Người nhận
                                        </h2>
                                        <p className="mt-3">
                                            {order.recipient_name} · {order.recipient_phone}
                                        </p>
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            {[
                                                order.shipping_address_line1,
                                                order.shipping_address_line2,
                                                order.shipping_district,
                                                order.shipping_city,
                                                order.shipping_province,
                                                order.shipping_country,
                                                order.shipping_postal_code,
                                            ]
                                                .filter(Boolean)
                                                .join(", ")}
                                        </p>
                                        {order.delivery_note && (
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                Ghi chú: {order.delivery_note}
                                            </p>
                                        )}
                                    </div>
                                    <CustomerReturnSection
                                        channel={{ kind: "retail" }}
                                        orderId={order.id}
                                    />
                                </div>
                                <aside className="h-fit rounded-xl border bg-card p-5">
                                    <h2 className="text-xl font-semibold text-primary">
                                        Trạng thái và tổng tiền
                                    </h2>
                                    <p className="mt-4 text-sm">
                                        Đơn: {orderStates[order.order_status] ?? order.order_status}
                                    </p>
                                    <p className="mt-2 text-sm">
                                        Thanh toán:{" "}
                                        {orderStates[order.payment_status] ?? order.payment_status}
                                    </p>
                                    <p className="mt-2 text-sm">
                                        Phương thức:{" "}
                                        {order.payment_method === "cod" ? "COD" : "Chuyển khoản"}
                                    </p>
                                    <p className="mt-2 text-sm">
                                        Giao hàng:{" "}
                                        {orderStates[order.fulfillment_status] ??
                                            order.fulfillment_status}
                                    </p>
                                    <p className="mt-4 flex justify-between border-t pt-4 text-sm">
                                        <span>Tạm tính</span>
                                        <span>{money(order.subtotal)}</span>
                                    </p>
                                    <p className="mt-2 flex justify-between text-sm">
                                        <span>
                                            {order.promotion
                                                ? `Ưu đãi ${order.promotion.name}`
                                                : order.voucher_code
                                                  ? `Voucher ${order.voucher_code}`
                                                  : "Giảm giá"}
                                        </span>
                                        <span>−{money(order.discount_total)}</span>
                                    </p>
                                    <p className="mt-4 flex justify-between border-t pt-4 font-semibold">
                                        <span>Tổng cộng</span>
                                        <span>{money(order.grand_total)}</span>
                                    </p>
                                    <p className="mt-2 flex justify-between text-sm">
                                        <span>Đã thanh toán</span>
                                        <span>{money(order.paid_amount)}</span>
                                    </p>
                                    <p className="mt-2 flex justify-between text-sm">
                                        <span>Đã hoàn tiền ({order.refund_status})</span>
                                        <span>{money(order.refunded_amount)}</span>
                                    </p>
                                    <p className="mt-2 flex justify-between text-sm">
                                        <span>Thực thu sau hoàn</span>
                                        <span>{money(order.net_settled_amount)}</span>
                                    </p>
                                    {order.refunds.map((refund) => (
                                        <p
                                            key={refund.refund_code}
                                            className="mt-1 text-xs text-muted-foreground"
                                        >
                                            {refund.refund_code} · {money(refund.amount)} ·{" "}
                                            {new Date(refund.completed_at).toLocaleDateString(
                                                "vi-VN",
                                            )}
                                        </p>
                                    ))}
                                    <p className="mt-2 flex justify-between font-semibold">
                                        <span>Còn phải thanh toán</span>
                                        <span>{money(order.outstanding_amount)}</span>
                                    </p>
                                </aside>
                            </div>
                        </>
                    )
                )}
            </Container>
        </RetailGuard>
    );
}

export function CheckoutSuccessPage({ orderId }: { orderId: number }) {
    const { user } = useAuth();
    const query = useQuery({
        queryKey: retailKeys.order(user?.id, orderId),
        queryFn: () => retailCommerceApi.order(orderId),
        enabled: Boolean(user),
        retry: false,
    });
    const order = query.data?.data;
    return (
        <RetailGuard returnTo={`/checkout/success/${orderId}`}>
            <Container className="py-16 text-center md:py-24">
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState
                        message={retailErrorMessage(query.error)}
                        retry={() => query.refetch()}
                    />
                ) : (
                    order && (
                        <div className="mx-auto max-w-lg rounded-2xl border bg-card p-8 shadow-sm">
                            <ShoppingBag className="mx-auto text-emerald-700" size={48} />
                            <h1 className="mt-5 text-3xl text-primary">Đặt hàng thành công</h1>
                            <p className="mt-3 text-muted-foreground">
                                Mã đơn: <strong>{order.order_code}</strong>
                            </p>
                            <p className="mt-2 font-semibold text-primary">
                                Tổng tiền: {money(order.grand_total)}
                            </p>
                            <p className="mt-3 text-sm text-muted-foreground">
                                Đơn đã được xác nhận và giữ hàng. Trạng thái thanh toán: chưa thanh
                                toán.
                            </p>
                            <div className="mt-7 flex flex-wrap justify-center gap-3">
                                <ButtonLink to={`/my-orders/${order.id}`}>Xem đơn hàng</ButtonLink>
                                <ButtonLink to="/products" variant="outline">
                                    Tiếp tục mua sắm
                                </ButtonLink>
                            </div>
                        </div>
                    )
                )}
            </Container>
        </RetailGuard>
    );
}
