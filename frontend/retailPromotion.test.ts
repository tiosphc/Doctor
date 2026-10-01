import { expect, test } from "bun:test";
import { primaryRetailPromotion } from "./src/lib/retailPromotion";
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
        retail_price: { unit_price: "400000.00", currency: "VND", pricing_context: "retail" },
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

test("unconditional percentage and fixed discounts preview the one-item saving", () => {
    expect(primaryRetailPromotion(product({ retail_promotions: [discount()] }))).toMatchObject({
        badge: "-15%",
        savings: 60000,
        direct: true,
    });
    expect(
        primaryRetailPromotion(
            product({
                retail_promotions: [
                    discount({ discount_type: "fixed_amount", discount_value: "100000" }),
                ],
            }),
        ),
    ).toMatchObject({ badge: "GIẢM 100K", savings: 100000, direct: true });
});

test("minimum spend and usage limits keep the catalog price unchanged", () => {
    expect(
        primaryRetailPromotion(
            product({ retail_promotions: [discount({ minimum_order_amount: "800000" })] }),
        ),
    ).toMatchObject({ direct: false, condition: "Áp dụng cho đơn từ 800.000đ" });
    expect(
        primaryRetailPromotion(
            product({ retail_promotions: [discount({ per_buyer_usage_limit: 1 })] }),
        ),
    ).toMatchObject({ direct: false, condition: "Áp dụng khi ưu đãi còn lượt sử dụng" });
    expect(
        primaryRetailPromotion(
            product({ retail_promotions: [discount({ max_discount_amount: "30000" })] }),
        ),
    ).toMatchObject({ direct: false, condition: "Giảm tối đa 30.000đ" });
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
            product({ gift_promotions: [gift], retail_promotions: [discount()] }),
        )?.kind,
    ).toBe("gift");
    expect(
        primaryRetailPromotion(
            product({
                gift_promotions: [{ ...gift, gift_available: false }],
                retail_promotions: [discount()],
            }),
        )?.kind,
    ).toBe("discount");
    expect(primaryRetailPromotion(product())).toBeNull();
});
