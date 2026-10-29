import { expect, test } from "bun:test";
import { primaryRetailPromotion, retailDiscountPromotion } from "./src/lib/retailPromotion";
import { formatPercentage } from "./src/lib/formatPercentage";
import type { Product, RetailPromotionSummary } from "./src/types/product";

function product(overrides: Partial<Product> = {}): Product {
    return {
        id: 1,
        product_code: "CREAM",
        name: "Barrier Cream",
        slug: "barrier-cream",
        description: null,
        product_category_id: 1,
        brand_id: null,
        category: null,
        brand: null,
        status: "active",
        variants: [],
        images: [],
        retail_price: {
            unit_price: "400000.00",
            discounted_unit_price: "340000.00",
            currency: "VND",
            pricing_context: "retail",
        },
        ...overrides,
    };
}

function discount(overrides: Partial<RetailPromotionSummary> = {}): RetailPromotionSummary {
    return {
        code: "SAVE15",
        discount_type: "percentage",
        discount_value: "15.00",
        max_discount_amount: null,
        minimum_order_amount: "0.00",
        total_usage_limit: null,
        per_buyer_usage_limit: null,
        ...overrides,
    };
}

test("the effective percentage promotion previews the one-item saving", () => {
    expect(
        primaryRetailPromotion(product({ retail_discount_promotion: discount() })),
    ).toMatchObject({
        badge: "-15%",
        savings: 60000,
        direct: true,
    });
});

test("a fixed amount promotion shows a VND badge and the discounted price", () => {
    expect(
        primaryRetailPromotion(
            product({
                retail_discount_promotion: discount({
                    discount_type: "fixed_amount",
                    discount_value: "50000.00",
                }),
                retail_price: {
                    unit_price: "400000.00",
                    discounted_unit_price: "350000.00",
                    currency: "VND",
                    pricing_context: "retail",
                },
            }),
        ),
    ).toMatchObject({ badge: "-50.000 đ", savings: 50000, direct: true });
});

test("the selected variant recalculates its own discounted price", () => {
    expect(
        retailDiscountPromotion(product({ retail_discount_promotion: discount() }), {
            unit_price: "600000.00",
            discounted_unit_price: "510000.00",
            currency: "VND",
            pricing_context: "retail",
        }),
    ).toMatchObject({
        savings: 90000,
        direct: true,
    });
});

test("minimum spend blocks a one-item preview while usage limits still show the discounted price", () => {
    expect(
        primaryRetailPromotion(
            product({
                retail_discount_promotion: discount({ minimum_order_amount: "800000" }),
                retail_price: {
                    unit_price: "400000.00",
                    discounted_unit_price: null,
                    currency: "VND",
                    pricing_context: "retail",
                },
            }),
        ),
    ).toMatchObject({ direct: false, condition: "Áp dụng cho đơn từ 800.000đ" });
    expect(
        primaryRetailPromotion(
            product({ retail_discount_promotion: discount({ per_buyer_usage_limit: 1 }) }),
        ),
    ).toMatchObject({ direct: true, savings: 60000 });
    expect(
        primaryRetailPromotion(
            product({
                retail_discount_promotion: discount({ max_discount_amount: "30000" }),
                retail_price: {
                    unit_price: "400000.00",
                    discounted_unit_price: "370000.00",
                    currency: "VND",
                    pricing_context: "retail",
                },
            }),
        ),
    ).toMatchObject({ direct: true, savings: 30000 });
    expect(
        primaryRetailPromotion(
            product({ retail_discount_promotion: discount({ total_usage_limit: 5 }) }),
        ),
    ).toMatchObject({ direct: true, savings: 60000 });
});

test("percentage formatter removes only trailing decimal zeroes", () => {
    expect(formatPercentage("7.00")).toBe("7%");
    expect(formatPercentage("7.50")).toBe("7.5%");
    expect(formatPercentage("7.25")).toBe("7.25%");
    expect(formatPercentage(null)).toBe("—");
});

test("one card selects a single primary offer and hides unavailable gifts", () => {
    const gift = {
        code: "GIFT",
        name: "Mask gift",
        buy_variant_id: null,
        minimum_buy_quantity: "2.000",
        gift_product_name: "Sheet Mask",
        gift_variant_name: "Single",
        gift_sku: "MASK-1",
        gift_quantity: "1.000",
        repeat_per_multiple: false,
        gift_available: true,
    };
    expect(primaryRetailPromotion(product({ gift_promotions: [gift] }))).toMatchObject({
        kind: "gift",
        badge: "MUA 2 TẶNG 1",
    });
    expect(
        primaryRetailPromotion(product({ gift_promotions: [{ ...gift, gift_available: false }] })),
    ).toBeNull();
    expect(
        primaryRetailPromotion(
            product({ gift_promotions: [gift], retail_discount_promotion: discount() }),
        )?.kind,
    ).toBe("gift");
    expect(
        retailDiscountPromotion(
            product({ gift_promotions: [gift], retail_discount_promotion: discount() }),
        ),
    ).toMatchObject({ kind: "discount", badge: "-15%" });
    expect(
        primaryRetailPromotion(
            product({
                gift_promotions: [{ ...gift, gift_available: false }],
                retail_discount_promotion: discount(),
            }),
        )?.kind,
    ).toBe("discount");
    expect(primaryRetailPromotion(product())).toBeNull();
});
