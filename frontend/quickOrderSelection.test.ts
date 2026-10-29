import { describe, expect, test } from "bun:test";
import {
    appendUniqueRows,
    catalogRow,
    matchingVariants,
    reviewRow,
    rowQuantityError,
} from "./src/features/dealers/quickOrderSelection";
import type { DealerProduct, DealerQuickOrderLine } from "./src/features/dealers/types";

const product: DealerProduct = {
    id: 1,
    product_code: "JUV-001",
    name: "Juvederm Ultra",
    slug: "juvederm-ultra",
    description: null,
    category: null,
    brand: null,
    images: [{ id: 1, url: "/image.jpg", alt_text: null, product_variant_id: 2, is_primary: true }],
    variants: [
        {
            id: 2,
            sku: "JUV-U3-3ML",
            variant_name: "Ultra 3",
            specifications: { size: "3ml" },
            unit: "Box",
            unit_symbol: "box",
            unit_precision: 0,
            dealer_price: {
                unit_price: "1250000",
                currency: "VND",
                minimum_quantity: "5.000",
                price_fingerprint: "p",
            },
        },
        {
            id: 3,
            sku: "JUV-U2-1ML",
            variant_name: "Ultra 2",
            specifications: { size: "1ml" },
            unit: "Box",
            unit_symbol: "box",
            unit_precision: 0,
            dealer_price: {
                unit_price: "1000000",
                currency: "VND",
                minimum_quantity: "10.000",
                price_fingerprint: "q",
            },
        },
    ],
};

describe("dealer quick order selection", () => {
    test("searches product name, partial SKU, variant name and specification value", () => {
        expect(matchingVariants(product, "juve")).toHaveLength(2);
        expect(matchingVariants(product, "u3-3").map((item) => item.id)).toEqual([2]);
        expect(matchingVariants(product, "Ultra 2").map((item) => item.id)).toEqual([3]);
        expect(matchingVariants(product, "3ml").map((item) => item.id)).toEqual([2]);
    });

    test("defaults quantity to current MOQ and rejects invalid quantities", () => {
        const row = catalogRow(product, product.variants[0]!);
        expect(row.quantity).toBe("5");
        expect(row.image_url).toBe("/image.jpg");
        expect(rowQuantityError(row)).toBeNull();
        for (const quantity of ["0", "-1", "1.5", "abc", "4"]) {
            expect(rowQuantityError({ ...row, quantity })).not.toBeNull();
        }
        expect(rowQuantityError({ ...row, quantity: "6" })).toBeNull();
        expect(
            rowQuantityError({ ...row, quantity: "7", available_quantity: "6.000" }),
        ).not.toBeNull();
    });

    test("adds several SKUs once and enforces the order line limit", () => {
        const first = catalogRow(product, product.variants[0]!);
        const second = catalogRow(product, product.variants[1]!);
        expect(appendUniqueRows([first], [first, second]).added).toBe(1);
        expect(
            appendUniqueRows([first], [first, second]).rows.map((row) => row.product_variant_id),
        ).toEqual([2, 3]);
        expect(appendUniqueRows([first], [second], 1).exceedsLimit).toBe(true);
    });

    test("reorder uses current review price, MOQ and stock rather than historical facts", () => {
        const old = catalogRow(product, product.variants[0]!);
        const current: DealerQuickOrderLine = {
            product_variant_id: 2,
            product_name: product.name,
            image_url: "/current-image.jpg",
            sku: "JUV-U3-3ML",
            variant_name: "Ultra 3",
            unit_name: "Box",
            unit_symbol: "box",
            unit_precision: 0,
            quantity: "5.000",
            unit_price: "1400000.00",
            minimum_quantity: "6.000",
            line_total: "7000000.00",
            available_quantity: "8.000",
            available_for_requested_quantity: true,
            availability_status: "available",
            errors: ["DEALER_MOQ_NOT_MET"],
        };
        const row = reviewRow(current, old);
        expect(row.unit_price).toBe("1400000.00");
        expect(row.image_url).toBe("/current-image.jpg");
        expect(row.minimum_quantity).toBe("6.000");
        expect(rowQuantityError(row)).not.toBeNull();
    });
});
