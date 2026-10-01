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
import { primaryRetailPromotion } from "@/lib/retailPromotion";
import { errorMessage } from "@/services/api";
import { productApi, productKeys } from "@/services/productApi";
import { retailCommerceApi, retailErrorMessage, retailKeys } from "@/services/retailCommerceApi";
import { RetailProductCard } from "@/components/retail/RetailProductCard";

const money = (value: string) =>
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
    const primaryPromotion = primaryRetailPromotion(product);
    const selected =
        product.variants.find((variant) => variant.id === selectedId) || product.variants[0];
    const image =
        product.images.find((item) => item.product_variant_id === selected?.id) ||
        product.images.find((item) => item.product_variant_id === null) ||
        product.images[0];
    return (
        <Container className="pb-20 pt-28 md:pt-36">
            <Link to="/products" className="text-sm text-primary underline underline-offset-4">
                ← Sản phẩm
            </Link>
            <div className="mt-8 grid gap-10 lg:grid-cols-2">
                <div>
                    <div className="aspect-square overflow-hidden rounded-xl bg-muted">
                        {image?.url && (
                            <img
                                src={image.url}
                                alt={image.alt_text || product.name}
                                className="size-full object-cover"
                            />
                        )}
                    </div>
                    {product.images.length > 1 && (
                        <div className="mt-3 flex gap-2">
                            {product.images.map((item) => (
                                <img
                                    key={item.id}
                                    src={item.url}
                                    alt={item.alt_text || product.name}
                                    className="size-16 rounded-md object-cover"
                                />
                            ))}
                        </div>
                    )}
                </div>
                <div>
                    <p className="label-luxury">{product.brand?.name || product.category?.name}</p>
                    <h1 className="mt-3 text-4xl text-primary">{product.name}</h1>
                    {primaryPromotion?.kind === "discount" && (
                        <div className="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-primary">
                            <strong>Ưu đãi {primaryPromotion.badge}</strong>
                            <p className="mt-1">
                                {primaryPromotion.direct
                                    ? "Ưu đãi được kiểm tra lại trong giỏ hàng."
                                    : primaryPromotion.condition}
                            </p>
                        </div>
                    )}
                    {!!product.gift_promotions?.length && (
                        <div className="mt-5 space-y-3">
                            {product.gift_promotions.map((promotion) => (
                                <div
                                    key={promotion.code}
                                    className="overflow-hidden rounded-2xl border border-amber-200 bg-gradient-to-b from-amber-50/80 to-white shadow-sm"
                                >
                                    <div className="flex items-center gap-2 bg-[#1b3a5c] px-4 py-2.5">
                                        <Gift aria-hidden="true" className="size-4 text-white" />
                                        <span className="text-sm font-bold tracking-wide text-white">
                                            Mua {product.name}, tặng {promotion.gift_product_name}
                                        </span>
                                    </div>
                                    <div className="p-4">
                                        <p className="text-sm text-muted-foreground">
                                            Mua{" "}
                                            {formatProductQuantity(promotion.minimum_buy_quantity)}{" "}
                                            × {product.name}
                                            {promotion.buy_variant_name
                                                ? ` / ${promotion.buy_variant_name}`
                                                : ""}
                                        </p>
                                        <div className="mt-3 flex items-center gap-4">
                                            {promotion.gift_image_url ? (
                                                <img
                                                    src={promotion.gift_image_url}
                                                    alt={promotion.gift_product_name}
                                                    className="size-14 shrink-0 rounded-xl object-cover"
                                                />
                                            ) : (
                                                <div className="flex size-14 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-500">
                                                    <Package
                                                        aria-hidden="true"
                                                        className="size-6"
                                                    />
                                                </div>
                                            )}
                                            <div className="min-w-0">
                                                <p className="text-xs font-bold uppercase tracking-wide text-amber-600">
                                                    Tặng kèm
                                                </p>
                                                <p className="mt-0.5 font-medium text-primary">
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
                                            <p className="mt-2 inline-flex items-center gap-1 rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900">
                                                Quà tặng tạm hết.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                    <p className="mt-3 text-sm text-muted-foreground">
                        Mã sản phẩm: {product.product_code}
                    </p>
                    <p className="mt-6 whitespace-pre-line leading-7 text-foreground/80">
                        {product.description}
                    </p>
                    {!!product.usage_instructions && (
                        <div className="mt-6 rounded-lg border p-4">
                            <h2 className="font-medium text-primary">Hướng dẫn sử dụng</h2>
                            <p className="mt-2 whitespace-pre-line text-sm leading-6 text-muted-foreground">
                                {product.usage_instructions}
                            </p>
                        </div>
                    )}
                    {!!product.youtube_videos?.length && (
                        <div className="mt-6 space-y-2">
                            <h2 className="font-medium text-primary">Video sản phẩm</h2>
                            {product.youtube_videos.map((url, index) => (
                                <a
                                    key={url}
                                    href={url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="block text-sm text-primary underline"
                                >
                                    Xem video {index + 1} trên YouTube
                                </a>
                            ))}
                        </div>
                    )}
                    <label className="mt-8 block text-sm font-medium" htmlFor="product-variant">
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
                        className="mt-2 w-full rounded-md border bg-background px-3 py-3"
                    >
                        {product.variants.map((variant) => (
                            <option key={variant.id} value={variant.id}>
                                {variant.variant_name} ({variant.sku})
                            </option>
                        ))}
                    </select>
                    <p className="mt-6 text-3xl font-semibold text-primary">
                        {selected?.retail_price && money(selected.retail_price.unit_price)}
                        {selected?.unit_symbol && (
                            <span className="ml-1 text-lg font-medium">
                                / {selected.unit_symbol}
                            </span>
                        )}
                    </p>
                    <p className="mt-2 text-sm text-muted-foreground">Giá bán lẻ hiện hành</p>
                    {selected?.specifications &&
                        Object.keys(selected.specifications).length > 0 && (
                            <dl className="mt-6 divide-y rounded-lg border px-4">
                                {Object.entries(selected.specifications).map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="flex justify-between gap-4 py-3 text-sm"
                                    >
                                        <dt className="text-muted-foreground">{label}</dt>
                                        <dd className="text-right">{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    <div className="mt-8 grid gap-3 rounded-xl border bg-card p-4 sm:p-5">
                        <label htmlFor="retail-quantity" className="text-sm font-medium">
                            Số lượng
                        </label>
                        <input
                            id="retail-quantity"
                            type="number"
                            min={1}
                            step={1}
                            value={quantity}
                            onChange={(event) => setQuantity(event.target.value)}
                            className="w-32 rounded-md border bg-background px-3 py-2"
                        />
                        {selected?.track_inventory === false && (
                            <p className="text-sm text-amber-800">
                                Quy cách này chưa hỗ trợ đặt hàng trực tuyến.
                            </p>
                        )}
                        <div className="flex flex-wrap gap-3">
                            <Button
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
                            <ButtonLink to="/cart" variant="outline">
                                Xem giỏ hàng
                            </ButtonLink>
                        </div>
                        {!isPositiveProductQuantity(quantity) && (
                            <p role="alert" className="text-sm text-red-700">
                                Số lượng phải là số nguyên dương.
                            </p>
                        )}
                        {cartError && (
                            <p role="alert" className="text-sm text-red-700">
                                {cartError}
                            </p>
                        )}
                        {added && (
                            <p role="status" className="text-sm text-emerald-700">
                                Đã thêm sản phẩm vào giỏ hàng. Bạn có thể tiếp tục mua sắm hoặc xem
                                giỏ hàng.
                            </p>
                        )}
                    </div>
                </div>
            </div>
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
