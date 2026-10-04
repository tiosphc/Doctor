import { formatProductQuantity } from "@/lib/productQuantity";
import { formatPromotionDiscount } from "@/lib/formatPercentage";
import type {
    GiftPromotionSummary,
    Product,
    RetailPrice,
    RetailPromotionSummary,
} from "@/types/product";

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

export type RetailDiscountPreview = Extract<RetailPromotionPreview, { kind: "discount" }>;

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

    return retailDiscountPromotion(product);
}

export function retailDiscountPromotion(
    product: Product,
    selectedPrice?: RetailPrice,
): RetailDiscountPreview | null {
    const promotion = product.retail_discount_promotion;
    if (!promotion) return null;
    const price = selectedPrice ?? product.retail_price ?? product.variants[0]?.retail_price;
    const base = Number(price?.unit_price);
    const discounted = Number(price?.discounted_unit_price);
    if (!Number.isFinite(base) || base <= 0) return null;
    const direct =
        price?.discounted_unit_price != null && Number.isFinite(discounted) && discounted < base;
    const savings = direct ? base - discounted : 0;
    const minimum = Number(promotion.minimum_order_amount);
    const badge = `-${formatPromotionDiscount(promotion.discount_type, promotion.discount_value)}`;
    const condition =
        minimum > base
            ? `Áp dụng cho đơn từ ${vnd(minimum)}`
            : promotion.total_usage_limit !== null || promotion.per_buyer_usage_limit !== null
              ? "Áp dụng khi ưu đãi còn lượt sử dụng"
              : promotion.max_discount_amount !== null
                ? `Giảm tối đa ${vnd(Number(promotion.max_discount_amount))}`
                : "Áp dụng khi thanh toán";
    return { kind: "discount", badge, promotion, direct, savings, condition };
}
