import { Link } from "@tanstack/react-router";
import { Gift, Package, Tag } from "lucide-react";
import { formatProductQuantity } from "@/lib/productQuantity";
import { primaryRetailPromotion } from "@/lib/retailPromotion";
import type { Product } from "@/types/product";

const money = (value: number) => `${Math.round(value).toLocaleString("vi-VN")}đ`;

/**
 * Badge color mapping:
 * - percentage discount → red
 * - fixed_amount discount → orange
 * - gift promotion → amber/gold
 */
function badgeClasses(promotion: ReturnType<typeof primaryRetailPromotion>): string {
    if (!promotion) return "";
    if (promotion.kind === "gift") {
        return "bg-gradient-to-r from-amber-500 to-yellow-400 text-white shadow-md shadow-amber-200/50";
    }
    if (promotion.promotion.discount_type === "percentage") {
        return "bg-gradient-to-r from-red-600 to-red-500 text-white shadow-md shadow-red-200/50";
    }
    return "bg-gradient-to-r from-orange-500 to-amber-400 text-white shadow-md shadow-orange-200/50";
}

export function RetailProductCard({ product }: { product: Product }) {
    const image =
        product.images.find((item) => item.product_variant_id === null) ?? product.images[0];
    const promotion = primaryRetailPromotion(product);
    const price = Number(
        product.retail_price?.unit_price ?? product.variants[0]?.retail_price?.unit_price ?? 0,
    );
    const directDiscount = promotion?.kind === "discount" && promotion.direct ? promotion : null;

    return (
        <Link
            to="/products/$slug"
            params={{ slug: product.slug }}
            className="group flex h-full min-w-0 flex-col overflow-hidden rounded-xl border bg-card shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
            <div className="relative aspect-square overflow-hidden bg-muted">
                {image?.url ? (
                    <img
                        src={image.url}
                        alt={image.alt_text || product.name}
                        className="size-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
                    />
                ) : (
                    <div className="flex size-full items-center justify-center text-muted-foreground">
                        <Package aria-hidden="true" className="size-12" />
                    </div>
                )}
                {promotion && (
                    <span
                        className={`absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-bold tracking-wide ${badgeClasses(promotion)}`}
                    >
                        {promotion.kind === "gift" && (
                            <Gift aria-hidden="true" className="size-3.5" />
                        )}
                        {promotion.kind === "discount" && (
                            <Tag aria-hidden="true" className="size-3.5" />
                        )}
                        {promotion.badge}
                    </span>
                )}
            </div>
            <div className="flex flex-1 flex-col p-5">
                <p className="text-xs uppercase tracking-widest text-muted-foreground">
                    {product.category?.name}
                </p>
                <h2 className="mt-2 line-clamp-2 min-h-12 text-xl leading-6 text-primary">
                    {product.name}
                </h2>
                <p className="mt-2 line-clamp-1 text-sm text-muted-foreground">
                    {product.variants.length} lựa chọn · {product.variants[0]?.variant_name}
                </p>
                <div className="mt-4 min-h-14">
                    {directDiscount && (
                        <p className="text-sm text-muted-foreground line-through">{money(price)}</p>
                    )}
                    <p className="font-semibold text-primary">
                        {money(
                            directDiscount ? Math.max(0, price - directDiscount.savings) : price,
                        )}
                        {product.variants[0]?.unit_symbol && (
                            <span className="font-normal">
                                {" "}
                                / {product.variants[0].unit_symbol}
                            </span>
                        )}
                    </p>
                </div>
                {promotion?.kind === "gift" && (
                    <div className="mt-3 overflow-hidden rounded-xl border-2 border-amber-300 bg-gradient-to-br from-amber-50 via-yellow-50 to-orange-50 p-3">
                        <div className="mb-2 flex items-center gap-1.5">
                            <Gift aria-hidden="true" className="size-4 text-amber-600" />
                            <p className="text-xs font-bold uppercase tracking-wide text-amber-700">
                                Tặng kèm
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            {promotion.gift.gift_image_url ? (
                                <img
                                    src={promotion.gift.gift_image_url}
                                    alt={promotion.gift.gift_product_name}
                                    className="size-12 shrink-0 rounded-lg border border-amber-200 object-cover"
                                />
                            ) : (
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-lg border border-amber-200 bg-white text-amber-400">
                                    <Package aria-hidden="true" className="size-5" />
                                </div>
                            )}
                            <p className="line-clamp-2 min-w-0 text-sm font-medium text-primary">
                                Mua {formatProductQuantity(promotion.gift.minimum_buy_quantity)}{" "}
                                {product.name}, tặng{" "}
                                {formatProductQuantity(promotion.gift.gift_quantity)}{" "}
                                {promotion.gift.gift_product_name}
                            </p>
                        </div>
                    </div>
                )}
                {promotion?.kind === "discount" && (
                    <div
                        className={`mt-3 overflow-hidden rounded-xl p-3 ${
                            promotion.promotion.discount_type === "percentage"
                                ? "bg-gradient-to-r from-red-50 via-rose-50 to-pink-50 ring-1 ring-red-200"
                                : "bg-gradient-to-r from-orange-50 via-amber-50 to-yellow-50 ring-1 ring-orange-200"
                        }`}
                    >
                        <p
                            className={`text-xs font-bold uppercase tracking-wide ${promotion.promotion.discount_type === "percentage" ? "text-red-600" : "text-orange-600"}`}
                        >
                            Ưu đãi
                        </p>
                        <p
                            className={`mt-1.5 text-sm font-medium ${promotion.promotion.discount_type === "percentage" ? "text-red-700" : "text-orange-700"}`}
                        >
                            {promotion.direct
                                ? promotion.promotion.discount_type === "percentage"
                                    ? `Tiết kiệm ${money(promotion.savings)}`
                                    : `Giảm trực tiếp ${money(promotion.savings)}`
                                : promotion.condition}
                        </p>
                    </div>
                )}
                <span className="mt-auto inline-flex pt-5 text-sm font-medium text-primary underline underline-offset-4">
                    Xem chi tiết
                </span>
            </div>
        </Link>
    );
}
