import { useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { Link, Navigate } from "@tanstack/react-router";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { Button } from "@/components/common/Button";
import { useAuth } from "@/contexts/AuthContext";
import { formatProductQuantity, isPositiveProductQuantity } from "@/lib/productQuantity";
import { formatPromotionDiscount } from "@/lib/formatPercentage";
import { ApiError, errorMessage } from "@/services/api";
import { dealerApi, dealerKeys } from "./api";

const money = (value: string) =>
    new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(Number(value));

function dealerError(error: unknown): string {
    if (error instanceof ApiError) {
        if (error.code === "DEALER_TIER_NOT_ASSIGNED")
            return "Tài khoản đại lý chưa được gán Tier. Vui lòng liên hệ quản trị viên.";
        if (error.status === 404)
            return "Tài khoản đại lý không còn khả dụng hoặc bạn không có quyền truy cập.";
    }
    return errorMessage(error);
}

export function DealerProductsPage() {
    const { user, isLoading } = useAuth();
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const accounts = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled: user?.role === "customer",
    });
    const selectedAccount = accounts.data?.data[0];
    const products = useQuery({
        queryKey: dealerKeys.products(user?.id, selectedAccount?.id ?? 0, search, page),
        queryFn: () =>
            dealerApi.products(selectedAccount!.id, {
                search,
                page,
            }),
        enabled: Boolean(selectedAccount),
        retry: false,
    });
    if (isLoading) return <LoadingState />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== "customer") return <Navigate to="/account" />;
    if (accounts.isPending) return <LoadingState />;
    if (accounts.isError)
        return (
            <ErrorState
                message={dealerError(accounts.error)}
                retry={() => void accounts.refetch()}
            />
        );
    if (!selectedAccount)
        return <EmptyState message="Bạn chưa có tài khoản đại lý đang hoạt động." />;

    return (
        <main className="dealer-page-wide space-y-7">
            <header className="space-y-2">
                <p className="label-luxury">Junie B2B</p>
                <h1 className="dealer-page-title text-primary">Sản phẩm</h1>
                <p className="text-[15px] leading-6 text-muted-foreground">
                    Khám phá sản phẩm và mức giá dành riêng cho hạng đại lý của bạn.
                </p>
            </header>
            <label className="grid max-w-md gap-2 text-sm font-medium">
                Tìm sản phẩm hoặc SKU
                <input
                    value={search}
                    onChange={(event) => {
                        setSearch(event.target.value);
                        setPage(1);
                    }}
                    className="dealer-control rounded-md border bg-background px-3 py-2"
                    placeholder="Tên sản phẩm, mã hoặc SKU"
                />
            </label>
            {products.isPending ? (
                <LoadingState />
            ) : products.isError ? (
                <ErrorState
                    message={dealerError(products.error)}
                    retry={() => void products.refetch()}
                />
            ) : products.data.data.length === 0 ? (
                <EmptyState message="Chưa có sản phẩm đại lý được cấu hình giá phù hợp." />
            ) : (
                <>
                    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {products.data.data.map((product) => {
                            const image =
                                product.images.find((item) => item.product_variant_id === null) ??
                                product.images[0];
                            const first = product.variants[0];
                            if (!first) return null;
                            return (
                                <Link
                                    key={product.id}
                                    to="/dealer/products/$slug"
                                    params={{ slug: product.slug }}
                                    className="overflow-hidden rounded-xl border bg-card transition-shadow hover:shadow-lg"
                                >
                                    <div className="aspect-square bg-muted">
                                        {image && (
                                            <img
                                                src={image.url}
                                                alt={image.alt_text || product.name}
                                                className="size-full object-cover"
                                            />
                                        )}
                                    </div>
                                    <div className="space-y-2.5 p-5 sm:p-6">
                                        <p className="text-xs uppercase tracking-widest text-muted-foreground">
                                            {product.brand?.name || product.category?.name}
                                        </p>
                                        <h2 className="text-xl text-primary">{product.name}</h2>
                                        {!!product.gift_promotions?.length && (
                                            <span className="inline-block rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">
                                                Có quà tặng
                                            </span>
                                        )}
                                        {product.dealer_discount_promotion &&
                                            first.dealer_price.discounted_unit_price && (
                                                <span className="inline-block rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700">
                                                    -
                                                    {formatPromotionDiscount(
                                                        product.dealer_discount_promotion
                                                            .discount_type,
                                                        product.dealer_discount_promotion
                                                            .discount_value,
                                                    )}
                                                </span>
                                            )}
                                        <p className="text-sm text-muted-foreground">
                                            {product.variants.length} biến thể đang khả dụng
                                        </p>
                                        {first.dealer_price.discounted_unit_price && (
                                            <p className="text-sm text-muted-foreground line-through">
                                                {money(first.dealer_price.unit_price)}
                                            </p>
                                        )}
                                        <p className="text-lg font-semibold text-primary">
                                            {money(
                                                first.dealer_price.discounted_unit_price ??
                                                    first.dealer_price.unit_price,
                                            )}
                                            {(first.unit_symbol || first.unit) &&
                                                ` / ${first.unit_symbol || first.unit}`}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Giá dành cho hạng{" "}
                                            {products.data.effective_tier?.name ?? "đại lý"}
                                        </p>
                                        <p className="dealer-meta text-muted-foreground">
                                            Giá Tier tham khảo. Giá cuối xác định theo địa chỉ giao
                                            hàng.
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            MOQ: {Number(first.dealer_price.minimum_quantity)}{" "}
                                            {first.unit_symbol || first.unit || "sản phẩm"}
                                        </p>
                                    </div>
                                </Link>
                            );
                        })}
                    </div>
                    <Pagination
                        current={products.data.meta.current_page}
                        last={products.data.meta.last_page}
                        onPage={setPage}
                    />
                </>
            )}
        </main>
    );
}

export function DealerProductDetailPage({ slug }: { slug: string }) {
    const { user, isLoading } = useAuth();
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [quantity, setQuantity] = useState("1");
    const accounts = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled: user?.role === "customer",
    });
    const accountId = accounts.data?.data[0]?.id ?? 0;
    const query = useQuery({
        queryKey: dealerKeys.product(user?.id, accountId, slug),
        queryFn: () => dealerApi.product(accountId, slug),
        enabled: user?.role === "customer" && accountId > 0,
        retry: false,
    });
    const quote = useMutation({
        mutationFn: ({ variantId, amount }: { variantId: number; amount: string }) =>
            dealerApi.quote(accountId, variantId, amount),
    });
    if (isLoading) return <LoadingState />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== "customer") return <Navigate to="/account" />;
    if (accounts.isPending) return <LoadingState />;
    if (accounts.isError)
        return (
            <ErrorState
                message={dealerError(accounts.error)}
                retry={() => void accounts.refetch()}
            />
        );
    if (accountId <= 0)
        return <EmptyState message="Bạn chưa có tài khoản đại lý đang hoạt động." />;
    if (query.isPending) return <LoadingState />;
    if (query.isError)
        return <ErrorState message={dealerError(query.error)} retry={() => void query.refetch()} />;
    const product = query.data.data;
    const selected =
        product.variants.find((variant) => variant.id === selectedId) ?? product.variants[0];
    if (!selected) return <EmptyState message="Chưa có biến thể đại lý được cấu hình giá." />;
    const image =
        product.images.find((item) => item.product_variant_id === selected.id) ??
        product.images.find((item) => item.product_variant_id === null) ??
        product.images[0];
    return (
        <main className="dealer-page-wide">
            <Link to="/dealer/products" className="text-sm text-primary underline">
                ← Sản phẩm
            </Link>
            <div className="mt-7 grid gap-8 lg:grid-cols-2">
                <div className="aspect-square overflow-hidden rounded-xl bg-muted">
                    {image && (
                        <img
                            src={image.url}
                            alt={image.alt_text || product.name}
                            className="size-full object-cover"
                        />
                    )}
                </div>
                <div className="space-y-5">
                    <h1 className="dealer-page-title text-primary">{product.name}</h1>
                    {(!!product.gift_promotions?.length || !!product.dealer_discount_promotion) && (
                        <div className="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">
                            <strong>Ưu đãi có thể áp dụng</strong>
                            {product.dealer_discount_promotion && (
                                <p>
                                    {product.dealer_discount_promotion.name}: giảm{" "}
                                    {formatPromotionDiscount(
                                        product.dealer_discount_promotion.discount_type,
                                        product.dealer_discount_promotion.discount_value,
                                    )}
                                </p>
                            )}
                            {product.gift_promotions?.map((promotion) => (
                                <p key={promotion.code}>
                                    <strong>{promotion.name}</strong>
                                    <br />
                                    Mua {formatProductQuantity(
                                        promotion.minimum_buy_quantity,
                                    )} × {product.name}
                                    {promotion.buy_variant_name
                                        ? ` / ${promotion.buy_variant_name}`
                                        : ""}{" "}
                                    → Tặng {formatProductQuantity(promotion.gift_quantity)} ×{" "}
                                    {promotion.gift_product_name} / {promotion.gift_variant_name}.
                                    {promotion.repeat_per_multiple &&
                                        " Tặng theo mỗi bội số mua đủ."}
                                    {!promotion.gift_available && (
                                        <span className="block text-amber-900">
                                            Quà tặng tạm hết.
                                        </span>
                                    )}
                                </p>
                            ))}
                        </div>
                    )}
                    <p className="text-sm text-muted-foreground">{product.product_code}</p>
                    {product.description && (
                        <p className="whitespace-pre-line leading-7">{product.description}</p>
                    )}
                    <label className="grid gap-2 text-sm font-medium">
                        Biến thể
                        <select
                            value={selected.id}
                            onChange={(event) => {
                                setSelectedId(Number(event.target.value));
                                setQuantity("1");
                                quote.reset();
                            }}
                            className="dealer-control rounded-md border bg-background px-3 py-2"
                        >
                            {product.variants.map((variant) => (
                                <option key={variant.id} value={variant.id}>
                                    {variant.variant_name} ({variant.sku})
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="rounded-xl border bg-card p-5">
                        {selected.dealer_price.discounted_unit_price && (
                            <p className="text-sm text-muted-foreground line-through">
                                {money(selected.dealer_price.unit_price)}
                            </p>
                        )}
                        <p className="text-sm text-muted-foreground">Giá dành cho bạn</p>
                        <p className="mt-2 text-3xl font-semibold text-primary">
                            {money(
                                selected.dealer_price.discounted_unit_price ??
                                    selected.dealer_price.unit_price,
                            )}
                            {(selected.unit_symbol || selected.unit) && (
                                <span className="ml-1 text-lg font-medium">
                                    / {selected.unit_symbol || selected.unit}
                                </span>
                            )}
                        </p>
                        {product.dealer_discount_promotion &&
                            selected.dealer_price.discounted_unit_price && (
                                <span className="mt-2 inline-flex rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700">
                                    -
                                    {formatPromotionDiscount(
                                        product.dealer_discount_promotion.discount_type,
                                        product.dealer_discount_promotion.discount_value,
                                    )}
                                </span>
                            )}
                        <p className="mt-2 text-sm">
                            MOQ: {Number(selected.dealer_price.minimum_quantity)}{" "}
                            {selected.unit_symbol || selected.unit || "sản phẩm"}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Giá Tier tham khảo. Giá cuối xác định theo địa chỉ giao hàng.
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Đơn vị: {selected.unit || selected.unit_symbol || "—"}
                        </p>
                        <Link
                            to="/dealer/quick-order"
                            search={{ sku: selected.sku, reorder: 0 }}
                            className="dealer-action mt-4 rounded-md bg-primary px-4 py-2 text-primary-foreground"
                        >
                            Đặt hàng nhanh
                        </Link>
                    </div>
                    <div className="rounded-xl border bg-card p-5">
                        <label
                            htmlFor="dealer-quote-quantity"
                            className="block text-sm font-medium"
                        >
                            Số lượng muốn xem giá
                        </label>
                        <div className="mt-3 flex flex-wrap gap-3">
                            <input
                                id="dealer-quote-quantity"
                                type="number"
                                min={1}
                                step={1}
                                value={quantity}
                                onChange={(event) => {
                                    setQuantity(event.target.value);
                                    quote.reset();
                                }}
                                className="dealer-control w-32 rounded-md border bg-background px-3 py-2"
                            />
                            <Button
                                disabled={quote.isPending || !isPositiveProductQuantity(quantity)}
                                onClick={() =>
                                    quote.mutate({ variantId: selected.id, amount: quantity })
                                }
                            >
                                {quote.isPending ? "Đang tính..." : "Xem báo giá"}
                            </Button>
                        </div>
                        {!isPositiveProductQuantity(quantity) && (
                            <p role="alert" className="mt-3 text-sm text-red-700">
                                Số lượng phải là số nguyên dương.
                            </p>
                        )}
                        {quote.isError && (
                            <p role="alert" className="mt-3 text-sm text-red-700">
                                {dealerError(quote.error)}
                            </p>
                        )}
                        {quote.data && (
                            <p role="status" className="mt-3 text-sm">
                                Thành tiền tham khảo:{" "}
                                <strong>
                                    {money(
                                        quote.data.data.discounted_line_total ??
                                            quote.data.data.line_total,
                                    )}
                                </strong>
                                {quote.data.data.promotion && (
                                    <span className="ml-2 text-rose-700">
                                        Giảm{" "}
                                        {formatPromotionDiscount(
                                            quote.data.data.promotion.discount_type,
                                            quote.data.data.promotion.discount_value,
                                        )}
                                    </span>
                                )}
                                .{" "}
                                {quote.data.data.meets_moq
                                    ? "Số lượng đạt MOQ."
                                    : `Chưa đạt MOQ ${Number(quote.data.data.minimum_quantity)}.`}
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </main>
    );
}
