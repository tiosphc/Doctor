import { expect, test } from "bun:test";
import {
    giftModeForRule,
    giftRuleForMode,
    giftRuleWithBuyProduct,
} from "./src/pages/admin/promotionGiftRuleState";
import type { Product } from "./src/types/product";
import type { SalesPromotionGiftRule } from "./src/services/salesPromotionApi";

const product = (id: number, variantId: number) =>
    ({
        id,
        variants: [{ id: variantId, status: "active", track_inventory: true }],
    }) as Product;

const rule: SalesPromotionGiftRule = {
    buy_product_id: null,
    buy_variant_id: null,
    minimum_buy_quantity: "2",
    gift_product_id: null,
    gift_variant_id: null,
    gift_quantity: "1",
    repeat_per_multiple: false,
};

test("create mode maps a selected product to the same-product gift payload", () => {
    const sameBeforeBuy = giftRuleForMode(rule, "same");
    const selected = giftRuleWithBuyProduct(sameBeforeBuy, product(10, 101), "same");
    expect(selected).toEqual({
        ...rule,
        buy_product_id: 10,
        gift_product_id: 10,
        gift_variant_id: 101,
    });
    expect(giftModeForRule(selected)).toBe("same");
    const changedBuy = giftRuleWithBuyProduct(selected, product(20, 201), "same");
    expect(changedBuy.buy_product_id).toBe(20);
    expect(changedBuy.gift_product_id).toBe(20);
    expect(changedBuy.gift_variant_id).toBe(201);
});

test("edit mode recognizes existing A-to-A and A-to-B rules without changing rule fields", () => {
    const same = {
        ...rule,
        buy_product_id: 10,
        buy_variant_id: 101,
        gift_product_id: 10,
        gift_variant_id: 101,
    };
    const other = { ...same, gift_product_id: 30, gift_variant_id: 301 };
    expect(giftModeForRule(same)).toBe("same");
    expect(giftModeForRule(other)).toBe("other");
    expect(giftRuleForMode(other, "same", product(10, 101))).toEqual({
        ...other,
        gift_product_id: 10,
        gift_variant_id: 101,
    });
    expect(giftRuleForMode(same, "other")).toEqual({
        ...same,
        gift_product_id: null,
        gift_variant_id: null,
    });
    expect(giftRuleWithBuyProduct(other, product(20, 201), "other")).toEqual({
        ...other,
        buy_product_id: 20,
        buy_variant_id: null,
    });
});
