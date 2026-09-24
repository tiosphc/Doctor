import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { Container } from "@/components/common/Container";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { errorMessage } from "@/services/api";
import { productApi, productKeys } from "@/services/productApi";
import type { Product } from "@/types/product";

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
                            <ProductCard key={product.id} product={product} />
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

function ProductCard({ product }: { product: Product }) {
    const image =
        product.images.find((item) => item.product_variant_id === null) || product.images[0];
    return (
        <Link
            to="/products/$slug"
            params={{ slug: product.slug }}
            className="group overflow-hidden rounded-xl border bg-card shadow-sm transition-shadow hover:shadow-lg"
        >
            <div className="aspect-square bg-muted">
                {image?.url && (
                    <img
                        src={image.url}
                        alt={image.alt_text || product.name}
                        className="size-full object-cover transition-transform group-hover:scale-105"
                    />
                )}
            </div>
            <div className="p-5">
                <p className="text-xs uppercase tracking-widest text-muted-foreground">
                    {product.brand?.name || product.category?.name}
                </p>
                <h2 className="mt-2 text-xl text-primary">{product.name}</h2>
                <p className="mt-2 text-sm text-muted-foreground">
                    {product.variants.length} lựa chọn · {product.variants[0]?.variant_name}
                </p>
                <p className="mt-4 font-semibold text-primary">
                    Giá Retail {money(product.retail_price?.unit_price || "0")}
                </p>
                <span className="mt-4 inline-block text-sm font-medium text-primary underline underline-offset-4">
                    Xem chi tiết
                </span>
            </div>
        </Link>
    );
}

export function ProductDetailPage({ slug }: { slug: string }) {
    const [selectedId, setSelectedId] = useState<number | null>(null);
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
                        onChange={(event) => setSelectedId(Number(event.target.value))}
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
                    </p>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Giá bán lẻ hiện hành · {selected?.unit_symbol || selected?.unit?.symbol}
                    </p>
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
                    <p className="mt-8 rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
                        Đặt hàng trực tuyến sẽ được mở trong giai đoạn tiếp theo. Vui lòng liên hệ
                        Junie để được tư vấn.
                    </p>
                </div>
            </div>
        </Container>
    );
}
