import { expect, test } from "bun:test";
import type { CatalogPriceRow } from "./src/services/productApi";
import {
    buildDealerPriceMatrix,
    groupCatalogPriceRows,
} from "./src/pages/admin/catalogPriceGroups";

function row(overrides: Partial<CatalogPriceRow>): CatalogPriceRow {
    return {
        variant_id: 10,
        product_id: 1,
        product_code: "PRODUCT-1",
        product_name: "Serum",
        product_image_url: "/storage/serum.jpg",
        sku: "SERUM-S",
        variant_name: "Small",
        unit_symbol: "chai",
        status: "active",
        product_status: "active",
        sellable: true,
        tier_id: 1,
        tier_name: "Silver",
        unit_price: "100000.00",
        minimum_quantity: 1,
        retail_reference_price: "120000.00",
        ...overrides,
    };
}

test("groups tier prices under each SKU and calculates the visible product summary", () => {
    const groups = groupCatalogPriceRows([
        row({}),
        row({ tier_id: 2, tier_name: "Gold", unit_price: "90000.00" }),
        row({ variant_id: 11, sku: "SERUM-L", variant_name: "Large", unit_price: null }),
        row({
            product_id: 2,
            product_code: "PRODUCT-2",
            product_name: "Cream",
            variant_id: 12,
            sku: "CREAM-S",
            unit_price: "50000.00",
        }),
    ]);

    expect(groups).toHaveLength(2);
    expect(groups[0]?.variants).toHaveLength(2);
    expect(groups[0]?.variants[0]?.prices.map((price) => price.tier_name)).toEqual([
        "Silver",
        "Gold",
    ]);
    expect(groups[0]?.variants[1]?.sku).toBe("SERUM-L");
    expect(groups[0]?.pricedCount).toBe(2);
    expect(groups[0]?.missingCount).toBe(1);
    expect(groups[0]?.minPrice).toBe(90000);
    expect(groups[0]?.maxPrice).toBe(100000);
    expect(groups[1]?.name).toBe("Cream");
});

test("dealer matrix keeps one row per SKU with dynamic tier columns and tier-specific MOQ", () => {
    const variants = groupCatalogPriceRows([
        row({ tier_id: 1, tier_name: "Silver", minimum_quantity: 1 }),
        row({ tier_id: 2, tier_name: "Gold", unit_price: null, minimum_quantity: null }),
        row({ tier_id: 3, tier_name: "Diamond", unit_price: "80000.00", minimum_quantity: 10 }),
        row({
            variant_id: 11,
            sku: "SERUM-M",
            variant_name: "Medium",
            unit_symbol: "tuýp",
            tier_id: 1,
            tier_name: "Silver",
            minimum_quantity: 2,
        }),
        row({
            variant_id: 11,
            sku: "SERUM-M",
            variant_name: "Medium",
            unit_symbol: "tuýp",
            tier_id: 2,
            tier_name: "Gold",
            unit_price: "95000.00",
            minimum_quantity: 5,
        }),
        row({
            variant_id: 11,
            sku: "SERUM-M",
            variant_name: "Medium",
            unit_symbol: "tuýp",
            tier_id: 3,
            tier_name: "Diamond",
            unit_price: null,
            minimum_quantity: null,
        }),
        row({
            variant_id: 12,
            sku: "SERUM-L",
            variant_name: "Large",
            unit_symbol: "bộ",
            tier_id: 1,
            tier_name: "Silver",
            unit_price: null,
            minimum_quantity: null,
        }),
        row({
            variant_id: 12,
            sku: "SERUM-L",
            variant_name: "Large",
            unit_symbol: "bộ",
            tier_id: 2,
            tier_name: "Gold",
            unit_price: "90000.00",
            minimum_quantity: 1,
        }),
        row({
            variant_id: 12,
            sku: "SERUM-L",
            variant_name: "Large",
            unit_symbol: "bộ",
            tier_id: 3,
            tier_name: "Diamond",
            unit_price: "75000.00",
            minimum_quantity: 3,
        }),
    ])[0]!.variants;
    const matrix = buildDealerPriceMatrix(variants);

    expect(matrix.tiers.map((tier) => tier.name)).toEqual(["Silver", "Gold", "Diamond"]);
    expect(matrix.rows).toHaveLength(3);
    expect(matrix.rows[0]?.pricesByTier.get(2)?.unit_price).toBeNull();
    expect(matrix.rows[0]?.pricesByTier.get(3)?.minimum_quantity).toBe(10);
    expect(matrix.rows[1]?.variant.unit).toBe("tuýp");
    expect(matrix.rows[1]?.pricesByTier.get(2)?.minimum_quantity).toBe(5);
    expect(matrix.rows[2]?.variant.unit).toBe("bộ");
    expect(matrix.rows[2]?.pricesByTier.get(1)?.unit_price).toBeNull();

    const goldOnly = buildDealerPriceMatrix(
        groupCatalogPriceRows([
            row({ tier_id: 2, tier_name: "Gold" }),
            row({ variant_id: 11, sku: "SERUM-M", tier_id: 2, tier_name: "Gold" }),
        ])[0]!.variants,
    );
    expect(goldOnly.tiers.map((tier) => tier.name)).toEqual(["Gold"]);
    expect(goldOnly.rows).toHaveLength(2);
});
