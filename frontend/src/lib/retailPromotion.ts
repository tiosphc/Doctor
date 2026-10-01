import { formatProductQuantity } from "@/lib/productQuantity";
import type { GiftPromotionSummary, Product, RetailPromotionSummary } from "@/types/product";

export type RetailPromotionPreview =
    | { kind: "gift"; badge: string; gift: GiftPromotionSummary }
    | {
          kind: "discount";
          badge: string;
          promotion: RetailPromotionSummary;
          direct: boolean;
          savings: number;
          condition: string;
      };

const vnd = (value: number) => `${Math.round(value).toLocaleString("vi-VN")}đ`;

export function primaryRetailPromotion(product: Product): RetailPromotionPreview | null {
    const gift = product.gift_promotions?.find((item) => item.gift_available);
    if (gift) {
        const buyQuantity = formatProductQuantity(gift.minimum_buy_quantity);
        const giftQuantity = formatProductQuantity(gift.gift_quantity);
        return {
            kind: "gift",
            badge: `MUA ${buyQuantity} TẶNG ${giftQuantity}`,
            gift,
        };
    }

    const price = Number(
        product.retail_price?.unit_price ?? product.variants[0]?.retail_price?.unit_price,
    );
    const discounts = (product.retail_promotions ?? [])
        .map((promotion) => {
            const value = Number(promotion.discount_value);
            const savings = Math.min(
                price,
                promotion.discount_type === "percentage" ? (price * value) / 100 : value,
                promotion.max_discount_amount === null
                    ? Number.POSITIVE_INFINITY
                    : Number(promotion.max_discount_amount),
            );
            const minimum = Number(promotion.minimum_order_amount);
            const direct =
                Number.isFinite(price) &&
                price > 0 &&
                minimum <= price &&
                promotion.max_discount_amount === null &&
                promotion.total_usage_limit === null &&
                promotion.per_buyer_usage_limit === null;
            const badge =
                promotion.discount_type === "percentage"
                    ? `-${Number(value.toFixed(2))}%`
                    : `GIẢM ${value >= 1000 ? `${Number((value / 1000).toFixed(1))}K` : vnd(value)}`;
            const condition =
                minimum > price
                    ? `Áp dụng cho đơn từ ${vnd(minimum)}`
                    : promotion.total_usage_limit !== null ||
                        promotion.per_buyer_usage_limit !== null
                      ? "Áp dụng khi ưu đãi còn lượt sử dụng"
                      : promotion.max_discount_amount !== null
                        ? `Giảm tối đa ${vnd(Number(promotion.max_discount_amount))}`
                        : "Áp dụng khi thanh toán";
            return { kind: "discount" as const, badge, promotion, direct, savings, condition };
        })
        .filter((preview) => Number.isFinite(preview.savings) && preview.savings > 0)
        .sort((left, right) => right.savings - left.savings);
    if (discounts[0]) return discounts[0];

    return null;
}
