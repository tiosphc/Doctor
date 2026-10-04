import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate } from "@tanstack/react-router";
import { Gift, Package } from "lucide-react";
import { Container } from "@/components/common/Container";
import { Button, ButtonLink } from "@/components/common/Button";
import { SuccessDialog } from "@/components/common/Feedback";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { useAuth } from "@/contexts/AuthContext";
import { formatProductQuantity, isPositiveProductQuantity } from "@/lib/productQuantity";
import { retailDiscountPromotion } from "@/lib/retailPromotion";
import { errorMessage } from "@/services/api";
import { productApi, productKeys } from "@/services/productApi";
import { retailCommerceApi, retailErrorMessage, retailKeys } from "@/services/retailCommerceApi";
import { RetailProductCard } from "@/components/retail/RetailProductCard";

const money = (value: string | number) =>
    new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(Number(value));

export function ProductsPage() {
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const [sort, setSort] = useState<"newest" | "name">("newest");
    const [category, setCategory] = useState("");
    const [brand, setBrand] = useState("");
    const filters = {
        search,
        sort,
        page,
        category: category ? Number(category) : undefined,
        brand: brand ? Number(brand) : undefined,
    };
    const options = useQuery({ queryKey: ["product-filters"], queryFn: productApi.filters });
    const query = useQuery({
        queryKey: productKeys.catalog(filters),
        queryFn: () => productApi.catalog(filters),
    });
    return (
        <Container className="pb-20 pt-28 md:pt-36">
            <p className="label-luxury">Junie Retail</p>
            <h1 className="mt-3 text-4xl text-primary md:text-5xl">Sản phẩm</h1>
            <p className="mt-3 max-w-2xl text-muted-foreground">
                Khám phá sản phẩm đang được bán lẻ tại Junie. Giá hiển thị là giá Retail hiện hành.
            </p>
            <div className="mt-8 grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-4">
                <input
                    className="min-w-0 flex-1 rounded-md border bg-background px-3 py-2"
                    aria-label="Tìm sản phẩm"
                    placeholder="Tìm sản phẩm hoặc SKU"
                    value={search}
                    onChange={(event) => {
                        setSearch(event.target.value);
                        setPage(1);
                    }}
                />
                <select
                    className="rounded-md border bg-background px-3 py-2"
                    aria-label="Lọc danh mục sản phẩm"
                    value={category}
                    onChange={(event) => {
                        setCategory(event.target.value);
                        setPage(1);
                    }}
                >
                    <option value="">Tất cả danh mục</option>
                    {options.data?.categories.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
                <select
                    className="rounded-md border bg-background px-3 py-2"
                    aria-label="Lọc thương hiệu"
                    value={brand}
                    onChange={(event) => {
                        setBrand(event.target.value);
                        setPage(1);
                    }}
                >
                    <option value="">Tất cả thương hiệu</option>
                    {options.data?.brands.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
                <select
                    className="rounded-md border bg-background px-3 py-2"
                    aria-label="Sắp xếp sản phẩm"
                    value={sort}
                    onChange={(event) => {
                        setSort(event.target.value as "newest" | "name");
                        setPage(1);
                    }}
                >
                    <option value="newest">Mới nhất</option>
                    <option value="name">Tên A–Z</option>
                </select>
            </div>
            {query.isPending ? (
                <LoadingState />
            ) : query.isError ? (
                <div className="mt-8">
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                </div>
            ) : query.data.data.length === 0 ? (
                <div className="mt-8">
                    <EmptyState message="Chưa có sản phẩm phù hợp." />
                </div>
            ) : (
                <>
                    <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {query.data.data.map((product) => (
                            <RetailProductCard key={product.id} product={product} />
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
    );
}

export function ProductDetailPage({ slug }: { slug: string }) {
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [quantity, setQuantity] = useState("1");
    const [added, setAdded] = useState(false);
    const [cartError, setCartError] = useState("");
    const { user } = useAuth();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const addToCart = useMutation({
        mutationFn: ({ variantId, amount }: { variantId: number; amount: string }) =>
            retailCommerceApi.add(variantId, amount),
        onSuccess: (result) => {
            queryClient.setQueryData(retailKeys.cart(user?.id), result);
            queryClient.invalidateQueries({ queryKey: retailKeys.review(user?.id) });
            setCartError("");
            setAdded(true);
        },
        onError: (reason) => setCartError(retailErrorMessage(reason)),
    });
    const query = useQuery({
        queryKey: productKeys.detail(slug),
        queryFn: () => productApi.detail(slug),
        retry: false,
    });
    if (query.isPending)
        return (
            <Container className="pb-20 pt-28">
                <LoadingState />
            </Container>
        );
    if (query.isError)
        return (
            <Container className="pb-20 pt-28">
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            </Container>
        );
    const product = query.data.data;
    const selected =
        product.variants.find((variant) => variant.id === selectedId) || product.variants[0];
    const selectedPrice = Number(selected?.retail_price?.unit_price ?? 0);
    const discountPromotion = retailDiscountPromotion(product, selected?.retail_price);
    const directDiscount = discountPromotion?.direct ? discountPromotion : null;
    const giftPromotions = product.gift_promotions ?? [];
    const image =
        product.images.find((item) => item.product_variant_id === selected?.id) ||
        product.images.find((item) => item.product_variant_id === null) ||
        product.images[0];
    const hasExtendedDescription = Boolean(
        product.description &&
        (product.description.length > 100 || product.description.includes("\n")),
    );
    return (
        <Container className="pb-20 pt-28 md:pt-36">
            <Link to="/products" className="text-sm text-primary underline underline-offset-4">
                ← Sản phẩm
            </Link>
            <div className="mt-6 grid items-start gap-8 md:gap-10 lg:grid-cols-2 xl:gap-12">
                <div className="min-w-0">
                    <div className="aspect-square overflow-hidden rounded-2xl bg-muted">
                        {image?.url && (
                            <img
                                src={image.url}
                                alt={image.alt_text || product.name}
                                className="size-full object-cover"
                            />
                        )}
                    </div>
                    {product.images.length > 1 && (
                        <div className="mt-3 flex gap-2 overflow-x-auto pb-1">
                            {product.images.map((item) => (
                                <img
                                    key={item.id}
                                    src={item.url}
                                    alt={item.alt_text || product.name}
                                    className="size-16 shrink-0 rounded-lg border object-cover"
                                />
                            ))}
                        </div>
                    )}
                </div>
                <div className="min-w-0">
                    <p className="label-luxury">{product.category?.name || product.brand?.name}</p>
                    <h1 className="mt-2 text-4xl leading-[1.12] text-primary sm:text-[42px]">
                        {product.name}
                    </h1>
                    {product.description && (
                        <p className="mt-3 line-clamp-3 whitespace-pre-line text-[15px] leading-7 text-foreground/75">
                            {product.description}
                        </p>
                    )}
                    <p className="mt-3 text-sm text-muted-foreground">
                        Mã sản phẩm: {product.product_code}
                    </p>
                    <label className="mt-6 block text-sm font-medium" htmlFor="product-variant">
                        Quy cách
                    </label>
                    <select
                        id="product-variant"
                        value={selected?.id}
                        onChange={(event) => {
                            setSelectedId(Number(event.target.value));
                            setQuantity("1");
                            setCartError("");
                        }}
                        className="mt-2 min-h-11 w-full rounded-lg border bg-background px-3 py-2 text-sm focus-premium"
                    >
                        {product.variants.map((variant) => (
                            <option key={variant.id} value={variant.id}>
                                {variant.variant_name} ({variant.sku})
                            </option>
                        ))}
                    </select>
                    <div className="mt-6 border-t pt-5">
                        {directDiscount && selected?.retail_price && (
                            <p className="text-sm text-muted-foreground line-through">
                                {money(selected.retail_price.unit_price)}
                            </p>
                        )}
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                            {selected?.retail_price && (
                                <strong className="text-[32px] leading-tight font-semibold tracking-tight text-primary sm:text-4xl">
                                    {money(
                                        directDiscount
                                            ? Math.max(0, selectedPrice - directDiscount.savings)
                                            : selectedPrice,
                                    )}
                                </strong>
                            )}
                            {selected?.retail_price && selected.unit_symbol && (
                                <span className="text-base text-primary/80">
                                    / {selected.unit_symbol}
                                </span>
                            )}
                            {directDiscount && (
                                <span className="inline-flex items-center rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold tracking-wide text-rose-700 ring-1 ring-rose-200">
                                    {directDiscount.badge.replace(/^-/, "GIẢM ")}
                                </span>
                            )}
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">Giá bán lẻ hiện hành</p>
                        {discountPromotion && (
                            <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                {discountPromotion.direct
                                    ? "Ưu đãi được kiểm tra lại trong giỏ hàng."
                                    : discountPromotion.condition}
                            </p>
                        )}
                    </div>
                    {giftPromotions.length > 0 && (
                        <div className="mt-6 space-y-3">
                            {giftPromotions.map((promotion) => (
                                <section
                                    key={promotion.code}
                                    className="min-w-0 overflow-hidden rounded-xl border border-[#d8dee8] bg-white shadow-sm"
                                >
                                    <div className="flex items-start gap-2 bg-[#1b3a5c] px-4 py-2.5">
                                        <Gift
                                            aria-hidden="true"
                                            className="mt-0.5 size-4 shrink-0 text-white"
                                        />
                                        <h2 className="min-w-0 break-words text-sm font-semibold leading-5 text-white">
                                            {promotion.name}
                                        </h2>
                                    </div>
                                    <div className="p-4">
                                        <p className="text-sm leading-6 text-muted-foreground">
                                            Mua{" "}
                                            {formatProductQuantity(promotion.minimum_buy_quantity)}{" "}
                                            × {product.name}
                                            {promotion.buy_variant_name
                                                ? ` / ${promotion.buy_variant_name}`
                                                : ""}
                                        </p>
                                        <div className="mt-3 flex min-w-0 items-center gap-3">
                                            {promotion.gift_image_url ? (
                                                <img
                                                    src={promotion.gift_image_url}
                                                    alt={promotion.gift_product_name}
                                                    className="size-14 shrink-0 rounded-lg object-cover"
                                                />
                                            ) : (
                                                <div className="flex size-14 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                                                    <Package
                                                        aria-hidden="true"
                                                        className="size-6"
                                                    />
                                                </div>
                                            )}
                                            <div className="min-w-0">
                                                <p className="text-xs font-semibold uppercase tracking-wide text-amber-700">
                                                    Tặng kèm
                                                </p>
                                                <p className="mt-0.5 break-words font-medium text-primary">
                                                    {promotion.gift_product_name}
                                                </p>
                                                <p className="mt-0.5 text-sm text-muted-foreground">
                                                    Gift ×
                                                    {formatProductQuantity(promotion.gift_quantity)}
                                                </p>
                                            </div>
                                        </div>
                                        {promotion.repeat_per_multiple && (
                                            <p className="mt-3 text-xs text-muted-foreground">
                                                Tặng theo mỗi bội số mua đủ.
                                            </p>
                                        )}
                                        {!promotion.gift_available && (
                                            <p className="mt-2 inline-flex rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-900">
                                                Quà tặng tạm hết.
                                            </p>
                                        )}
                                    </div>
                                </section>
                            ))}
                        </div>
                    )}
                    <div className="mt-6 border-t pt-5">
                        <label htmlFor="retail-quantity" className="block text-sm font-medium">
                            Số lượng
                        </label>
                        <input
                            id="retail-quantity"
                            type="number"
                            min={1}
                            step={1}
                            value={quantity}
                            onChange={(event) => setQuantity(event.target.value)}
                            className="focus-premium mt-2 min-h-11 w-28 rounded-lg border bg-background px-3 py-2 text-center text-sm"
                        />
                        {selected?.track_inventory === false && (
                            <p className="text-sm text-amber-800">
                                Quy cách này chưa hỗ trợ đặt hàng trực tuyến.
                            </p>
                        )}
                        <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                            <Button
                                className="w-full sm:w-auto"
                                disabled={
                                    addToCart.isPending ||
                                    selected?.track_inventory === false ||
                                    !isPositiveProductQuantity(quantity)
                                }
                                onClick={() => {
                                    if (!user) {
                                        navigate({
                                            to: "/login",
                                            search: { returnTo: `/products/${slug}` },
                                        });
                                        return;
                                    }
                                    if (selected)
                                        addToCart.mutate({
                                            variantId: selected.id,
                                            amount: quantity,
                                        });
                                }}
                            >
                                {addToCart.isPending ? "Đang thêm..." : "Thêm vào giỏ hàng"}
                            </Button>
                            <ButtonLink to="/cart" variant="outline" className="w-full sm:w-auto">
                                Xem giỏ hàng
                            </ButtonLink>
                        </div>
                        {!isPositiveProductQuantity(quantity) && (
                            <p role="alert" className="mt-3 text-sm text-red-700">
                                Số lượng phải là số nguyên dương.
                            </p>
                        )}
                        {cartError && (
                            <p role="alert" className="mt-3 text-sm text-red-700">
                                {cartError}
                            </p>
                        )}
                        {added && (
                            <p role="status" className="mt-3 text-sm text-emerald-700">
                                Đã thêm sản phẩm vào giỏ hàng. Bạn có thể tiếp tục mua sắm hoặc xem
                                giỏ hàng.
                            </p>
                        )}
                    </div>
                </div>
            </div>
            {(hasExtendedDescription ||
                product.usage_instructions ||
                (selected?.specifications && Object.keys(selected.specifications).length > 0) ||
                Boolean(product.youtube_videos?.length)) && (
                <div className="mt-12 grid gap-8 border-t pt-8 md:grid-cols-2 md:gap-10">
                    {hasExtendedDescription && (
                        <section>
                            <h2 className="text-xl text-primary">Mô tả sản phẩm</h2>
                            <p className="mt-3 whitespace-pre-line text-[15px] leading-7 text-foreground/80">
                                {product.description}
                            </p>
                        </section>
                    )}
                    {selected?.specifications &&
                        Object.keys(selected.specifications).length > 0 && (
                            <section>
                                <h2 className="text-xl text-primary">Thông số sản phẩm</h2>
                                <dl className="mt-3 divide-y border-t border-b">
                                    {Object.entries(selected.specifications).map(
                                        ([label, value]) => (
                                            <div
                                                key={label}
                                                className="flex justify-between gap-4 py-3 text-sm"
                                            >
                                                <dt className="min-w-0 break-words text-muted-foreground">
                                                    {label}
                                                </dt>
                                                <dd className="min-w-0 break-words text-right">
                                                    {value}
                                                </dd>
                                            </div>
                                        ),
                                    )}
                                </dl>
                            </section>
                        )}
                    {product.usage_instructions && (
                        <section>
                            <h2 className="text-xl text-primary">Hướng dẫn sử dụng</h2>
                            <p className="mt-3 whitespace-pre-line text-sm leading-7 text-muted-foreground">
                                {product.usage_instructions}
                            </p>
                        </section>
                    )}
                    {!!product.youtube_videos?.length && (
                        <section>
                            <h2 className="text-xl text-primary">Video sản phẩm</h2>
                            <div className="mt-3 flex flex-col gap-2">
                                {product.youtube_videos.map((url, index) => (
                                    <a
                                        key={url}
                                        href={url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-sm text-primary underline underline-offset-4"
                                    >
                                        Xem video {index + 1} trên YouTube
                                    </a>
                                ))}
                            </div>
                        </section>
                    )}
                </div>
            )}
            <SuccessDialog
                message={
                    added
                        ? "Đã thêm sản phẩm vào giỏ hàng. Bạn có thể xem giỏ hàng hoặc tiếp tục mua sắm."
                        : ""
                }
                title="Thêm vào giỏ hàng thành công"
                onClose={() => setAdded(false)}
            />
        </Container>
    );
}
