import { expect, test } from "bun:test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { formatProductQuantity, isPositiveProductQuantity } from "./src/lib/productQuantity";
import { StockStep } from "./src/pages/admin/ProductWizardSteps";
import {
    emptyWizard,
    generateVariantSku,
    parseDealerCsv,
    payload,
    stepForField,
    steps,
    validateStep,
    type WizardData,
} from "./src/pages/admin/productWizard";

const ready = (): WizardData => ({
    ...emptyWizard,
    name: "Serum",
    sku: "SERUM-1",
    product_category_id: 1,
    unit_id: 1,
    retail_price: "120000",
});

test("new products track inventory by default and the stock step hides shipping inputs", () => {
    expect(emptyWizard.track_inventory).toBe(true);
    expect(steps[4]).toBe("Kho & tồn kho");
    const props = {
        data: ready(),
        update: () => {},
        errors: {},
        categories: [],
        brands: [],
        units: [],
        warehouses: [],
        tiers: [],
        images: [],
        busy: false,
        uploadImages: async () => {},
        removeImage: async () => {},
        updateImage: async () => {},
    };
    const html = renderToStaticMarkup(createElement(StockStep, props));
    expect(html).toContain("Quản lý kho");
    expect(html).toContain("Bật theo dõi tồn kho");
    expect(html).toMatch(/type="checkbox"[^>]*checked=""/);
    const untrackedHtml = renderToStaticMarkup(
        createElement(StockStep, { ...props, data: { ...props.data, track_inventory: false } }),
    );
    expect(untrackedHtml).not.toMatch(/type="checkbox"[^>]*checked=""/);
    expect(html).not.toContain("Đóng gói / vận chuyển");
    expect(html).not.toContain('id="weight"');
    expect(payload({ ...ready(), weight: "0.125" })["weight"]).toBe("0.125");
});

test("basic step reports required name, SKU, category, unit and channel", () => {
    const errors = validateStep(0, { ...emptyWizard, sellable_retail: false }, 0);
    expect(Object.keys(errors)).toEqual([
        "name",
        "sku",
        "product_category_id",
        "unit_id",
        "channels",
    ]);
    expect(validateStep(0, { ...ready(), sku: "" }, 0)["sku"]).toBeDefined();
});

test("image and YouTube validation stay on media step", () => {
    expect(validateStep(1, ready(), 0)["images"]).toBeDefined();
    const errors = validateStep(
        1,
        { ...ready(), youtube_videos: ["https://example.com/video"] },
        1,
    );
    expect(errors["youtube_videos.0"]).toBeDefined();
});

test("simple product needs no variant and named variants get predictable SKUs", () => {
    expect(validateStep(2, ready(), 1)).toEqual({});
    const data: WizardData = {
        ...ready(),
        has_variants: true,
        variants: [],
    };
    expect(validateStep(2, data, 1)["variants"]).toBeDefined();
    const variants = ["5ml", "10ml"].map((variant_name) => ({
        variant_name,
        sku: generateVariantSku(data.sku, variant_name),
        specifications: {},
        image_id: null,
        retail_price_override: "",
        initial_stock: "",
    }));
    expect(variants.map((variant) => variant.sku)).toEqual(["SERUM-1-5ML", "SERUM-1-10ML"]);
    expect(generateVariantSku("SERUM-1", "Lọ 5ml")).toBe("SERUM-1-LO-5ML");
    expect(validateStep(2, { ...data, variants }, 1)).toEqual({});
});

test("variant names and generated SKUs must be unique and nonempty", () => {
    const data = {
        ...ready(),
        has_variants: true,
        variants: [
            {
                sku: "SERUM-1-5ML",
                variant_name: "5ml",
                specifications: {},
                image_id: null,
                retail_price_override: "",
                initial_stock: "",
            },
            {
                sku: "SERUM-1-5ML",
                variant_name: "5ML",
                specifications: {},
                image_id: null,
                retail_price_override: "",
                initial_stock: "",
            },
        ],
    };
    const errors = validateStep(2, data, 1);
    expect(errors["variants.1.variant_name"]).toBeDefined();
    expect(errors["variants.1.sku"]).toBeDefined();
    expect(
        validateStep(2, { ...data, variants: [{ ...data.variants[0]!, variant_name: "" }] }, 1)[
            "variants.0.variant_name"
        ],
    ).toBeDefined();
});

test("retail accepts zero and removes historical quantity breaks from a new submission", () => {
    expect(validateStep(3, { ...ready(), retail_price: "0" }, 1)).toEqual({});
    expect(
        payload({ ...ready(), retail_breaks: [{ min_quantity: "10", unit_price: "90000" }] })[
            "retail_breaks"
        ],
    ).toEqual([]);
    expect(validateStep(3, { ...ready(), retail_price: "-1" }, 1)["retail_price"]).toBeDefined();
});

test("dealer prices require a Tier, SKU and positive MOQ with one row per pair", () => {
    expect(validateStep(3, ready(), 1)).toEqual({});
    const data: WizardData = {
        ...ready(),
        sellable_retail: false,
        sellable_dealer: true,
        retail_price: "",
        dealer_rules: [{ tier_id: 1, sku: "SERUM-1", min_quantity: "-1", unit_price: "90000" }],
    };
    expect(validateStep(3, data, 1)["dealer_rules.0.min_quantity"]).toBeDefined();
    expect(validateStep(3, { ...data, dealer_rules: [] }, 1)["dealer_rules"]).toBeDefined();
    expect(
        validateStep(
            3,
            {
                ...data,
                dealer_rules: [{ tier_id: 1, sku: "SERUM-1", min_quantity: "10", unit_price: "0" }],
            },
            1,
        )["dealer_rules.0.unit_price"],
    ).toBeDefined();
    expect(
        validateStep(
            3,
            {
                ...data,
                dealer_rules: [
                    { tier_id: 1, sku: "SERUM-1", min_quantity: "10", unit_price: "90000" },
                    { tier_id: 1, sku: "SERUM-1", min_quantity: "20", unit_price: "80000" },
                ],
            },
            1,
        )["dealer_rules.1.sku"],
    ).toBeDefined();
});

test("gift-only product can omit selling channels and prices but requires tracked stock", () => {
    const gift = {
        ...ready(),
        can_be_gift: true,
        gift_only: true,
        sellable_retail: false,
        sellable_dealer: false,
        retail_price: "",
        track_inventory: true,
    };
    expect(validateStep(0, gift, 0)).toEqual({});
    expect(validateStep(3, gift, 1)).toEqual({});
    expect(payload(gift)["retail_price"]).toBeNull();
    expect(
        validateStep(4, { ...gift, track_inventory: false }, 1)["track_inventory"],
    ).toBeDefined();
});

test("dealer CSV import parses quoted values and rejects malformed headers", () => {
    expect(parseDealerCsv('Tier,SKU,MOQ,Price\n"Gold",SERUM-1,10,980000')).toEqual([
        { tier: "Gold", sku: "SERUM-1", moq: "10", price: "980000" },
    ]);
    expect(() => parseDealerCsv("Tier,Price\nGold,980000")).toThrow();
});

test("product quantities reject decimals while physical measurements retain precision", () => {
    const errors = validateStep(
        4,
        { ...ready(), track_inventory: false, initial_stock: "1.5", weight: "0.125" },
        1,
        3,
    );
    expect(errors["initial_stock"]).toBeDefined();
    expect(errors["weight"]).toBeUndefined();
    expect(["1", "2", "50"].every(isPositiveProductQuantity)).toBe(true);
    expect(["1.5", "1.001", "0", "-1", ""].some(isPositiveProductQuantity)).toBe(false);
    expect(formatProductQuantity("71.000")).toBe("71");
    expect(formatProductQuantity("1.250")).toBe("1.250");
    expect(errors["track_inventory"]).toBeDefined();
    expect(errors["warehouse_id"]).toBeDefined();
    expect(stepForField("sku")).toBe(0);
    expect(stepForField("youtube_videos.0")).toBe(1);
    expect(stepForField("variants.0.sku")).toBe(2);
    expect(stepForField("dealer_rules.0.min_quantity")).toBe(3);
    expect(stepForField("variants.0.initial_stock")).toBe(4);
});
