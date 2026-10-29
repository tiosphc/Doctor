import { useQuery } from "@tanstack/react-query";
import { formatPromotionDiscount } from "@/lib/formatPercentage";
import { Link, Navigate } from "@tanstack/react-router";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { Container } from "@/components/common/Container";
import { RetailProductCard } from "@/components/retail/RetailProductCard";
import { useAuth } from "@/contexts/AuthContext";
import { dealerApi, dealerKeys } from "@/features/dealers/api";
import { errorMessage } from "@/services/api";
import { formatProductQuantity } from "@/lib/productQuantity";
import { giftPromotionApi } from "@/services/giftPromotionApi";
import { productApi, productKeys } from "@/services/productApi";
import { useState } from "react";

export function GiftPromotionsPage({ dealer = false }: { dealer?: boolean }) {
    return dealer ? <DealerPromotionsPage /> : <RetailPromotionsPage />;
}

function RetailPromotionsPage() {
    const [page, setPage] = useState(1);
    const filters = { promotions_only: true, page };
    const products = useQuery({
        queryKey: productKeys.catalog(filters),
        queryFn: () => productApi.catalog(filters),
    });

    return (
        <Container className="pb-20 pt-28 md:pt-36">
            <p className="label-luxury">Junie Retail</p>
            <h1 className="mt-2 text-3xl text-primary md:text-4xl">Ưu đãi sản phẩm</h1>
            <p className="mt-2 text-sm text-muted-foreground">
                Khám phá sản phẩm đang có ưu đãi. Điều kiện được kiểm tra lại khi thanh toán.
            </p>
            {products.isPending ? (
                <LoadingState />
            ) : products.isError ? (
                <ErrorState
                    message={errorMessage(products.error)}
                    retry={() => void products.refetch()}
                />
            ) : products.data.data.length === 0 ? (
                <EmptyState message="Hiện chưa có ưu đãi sản phẩm phù hợp." />
            ) : (
                <>
                    <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {products.data.data.map((product) => (
                            <RetailProductCard key={product.id} product={product} />
                        ))}
                    </div>
                    <Pagination
                        current={products.data.meta.current_page}
                        last={products.data.meta.last_page}
                        onPage={setPage}
                    />
                </>
            )}
        </Container>
    );
}

function DealerPromotionsPage() {
    const { user, isLoading } = useAuth();
    const accounts = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled: user?.role === "customer",
    });
    const selected = accounts.data?.data[0];
    const promotions = useQuery({
        queryKey: ["gift-promotions", selected?.id],
        queryFn: () => giftPromotionApi.dealer(selected!.id),
        enabled: Boolean(selected),
    });
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
    return (
        <div className="dealer-page-wide space-y-0">
            <p className="label-luxury">Junie B2B</p>
            <h1 className="dealer-page-title mt-2 text-primary">Ưu đãi</h1>
            <p className="mt-1.5 text-[15px] leading-6 text-muted-foreground">
                Ưu đãi phù hợp được kiểm tra và áp dụng tự động khi đặt hàng.
            </p>
            {promotions.isPending ? (
                <LoadingState />
            ) : promotions.isError ? (
                <ErrorState
                    message={errorMessage(promotions.error)}
                    retry={() => void promotions.refetch()}
                />
            ) : !promotions.data?.data.length ? (
                <EmptyState message="Hiện chưa có ưu đãi quà tặng phù hợp." />
            ) : (
                <div className="mt-6 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    {promotions.data.data.map((promotion) => (
                        <article
                            key={`${promotion.code}-${promotion.buy_product_id}`}
                            className="rounded-xl border bg-card p-5 shadow-sm sm:p-6"
                        >
                            <span className="rounded-full bg-amber-100 px-3 py-1 text-[13px] font-semibold text-amber-900">
                                {promotion.discount_type === "buy_a_get_b"
                                    ? "Quà tặng"
                                    : "Giảm giá"}
                            </span>
                            <h2 className="mt-3 text-xl text-primary">{promotion.name}</h2>
                            {promotion.discount_type === "buy_a_get_b" ? (
                                <p className="mt-2 text-sm">
                                    Mua {formatProductQuantity(promotion.minimum_buy_quantity)} ×{" "}
                                    {promotion.buy_product_name}
                                    {promotion.buy_variant_name
                                        ? ` / ${promotion.buy_variant_name}`
                                        : ""}{" "}
                                    → Tặng {formatProductQuantity(promotion.gift_quantity)} ×{" "}
                                    {promotion.gift_product_name} / {promotion.gift_variant_name}.
                                </p>
                            ) : (
                                <p className="mt-2 text-sm">
                                    Giảm{" "}
                                    {formatPromotionDiscount(
                                        promotion.discount_type,
                                        promotion.discount_value,
                                    )}{" "}
                                    khi mua sản phẩm phù hợp.
                                </p>
                            )}
                            {promotion.discount_type === "buy_a_get_b" &&
                                promotion.repeat_per_multiple && (
                                    <p className="dealer-meta mt-2 text-muted-foreground">
                                        Tặng theo mỗi bội số mua đủ.
                                    </p>
                                )}
                            {promotion.discount_type === "buy_a_get_b" &&
                                !promotion.gift_available && (
                                    <p className="mt-2 text-sm text-amber-900">Quà tặng tạm hết.</p>
                                )}
                            {promotion.ends_at && (
                                <p className="dealer-meta mt-3 text-muted-foreground">
                                    Hạn đến:{" "}
                                    {new Date(promotion.ends_at).toLocaleDateString("vi-VN")}
                                </p>
                            )}
                            {!promotion.buy_available && (
                                <p className="mt-2 text-sm text-amber-900">Tạm hết hàng</p>
                            )}
                            {
                                <p className="dealer-meta mt-2 text-muted-foreground">
                                    Dành cho:{" "}
                                    {promotion.dealer_tiers?.length
                                        ? promotion.dealer_tiers.join(", ")
                                        : "mọi hạng đại lý"}
                                </p>
                            }
                            {selected ? (
                                promotion.buy_available ? (
                                    <Link
                                        to="/dealer/products/$slug"
                                        params={{ slug: promotion.buy_product_slug }}
                                        className="dealer-action mt-4 text-primary underline"
                                    >
                                        Xem sản phẩm
                                    </Link>
                                ) : (
                                    <span className="mt-4 inline-block text-sm text-muted-foreground">
                                        Tạm hết hàng
                                    </span>
                                )
                            ) : promotion.buy_available ? (
                                <Link
                                    to="/products/$slug"
                                    params={{ slug: promotion.buy_product_slug }}
                                    className="mt-4 inline-block text-sm font-medium text-primary underline"
                                >
                                    Xem sản phẩm
                                </Link>
                            ) : (
                                <span className="mt-4 inline-block text-sm text-muted-foreground">
                                    Tạm hết hàng
                                </span>
                            )}
                        </article>
                    ))}
                </div>
            )}
        </div>
    );
}
