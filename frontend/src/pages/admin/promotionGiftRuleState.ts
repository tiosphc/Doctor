import type { Product } from "@/types/product";
import type { SalesPromotionGiftRule } from "@/services/salesPromotionApi";

export type GiftMode = "other" | "same";

export function giftModeForRule(rule: SalesPromotionGiftRule): GiftMode {
    return rule.buy_product_id !== null && rule.buy_product_id === rule.gift_product_id
        ? "same"
        : "other";
}

export function giftRuleForMode(
    rule: SalesPromotionGiftRule,
    mode: GiftMode,
    buyProduct?: Product,
): SalesPromotionGiftRule {
    if (mode === "other") {
        return { ...rule, gift_product_id: null, gift_variant_id: null };
    }
    const variants =
        buyProduct?.variants.filter(
            (variant) => variant.status === "active" && variant.track_inventory !== false,
        ) ?? [];
    return {
        ...rule,
        gift_product_id: rule.buy_product_id,
        gift_variant_id: rule.buy_variant_id ?? (variants.length === 1 ? variants[0]!.id : null),
    };
}

export function giftRuleWithBuyProduct(
    rule: SalesPromotionGiftRule,
    product: Product,
    mode: GiftMode,
): SalesPromotionGiftRule {
    const next = { ...rule, buy_product_id: product.id, buy_variant_id: null };
    return mode === "same" ? giftRuleForMode(next, mode, product) : next;
}
