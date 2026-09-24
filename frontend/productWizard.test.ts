import { expect, test } from "bun:test";
import {
    emptyWizard,
    generateVariants,
    stepForField,
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

test("simple product needs no variant and generated variants preserve entered state", () => {
    expect(validateStep(2, ready(), 1)).toEqual({});
    const data: WizardData = {
        ...ready(),
        has_variants: true,
        attributes: [{ name: "Size", values: ["S", "M"] }],
        variants: [],
    };
    expect(validateStep(2, data, 1)["variants"]).toBeDefined();
    const variants = generateVariants(data);
    expect(variants.map((variant) => variant.sku)).toEqual(["SERUM-1-S", "SERUM-1-M"]);
    expect(
        generateVariants({ ...data, variants: [{ ...variants[0]!, initial_stock: "5" }] })[0]
            ?.initial_stock,
    ).toBe("5");
    expect(validateStep(2, { ...data, variants }, 1)).toEqual({});
});

test("variant attribute names and values must be unique and nonempty", () => {
    const data = {
        ...ready(),
        has_variants: true,
        attributes: [
            { name: "Size", values: ["S", "s"] },
            { name: "size", values: [] },
        ],
    };
    const errors = validateStep(2, data, 1);
    expect(errors["attributes.0.values.1"]).toBeDefined();
    expect(errors["attributes.1.name"]).toBeDefined();
    expect(errors["attributes.1.values"]).toBeDefined();
});

test("retail price and quantity breaks reject zero, negative and overlapping thresholds", () => {
    const errors = validateStep(
        3,
        {
            ...ready(),
            retail_price: "0",
            retail_breaks: [
                { min_quantity: "10", unit_price: "100000" },
                { min_quantity: "10", unit_price: "90000" },
            ],
        },
        1,
    );
    expect(errors["retail_price"]).toBeDefined();
    expect(errors["retail_breaks.1.min_quantity"]).toBeDefined();
    expect(validateStep(3, { ...ready(), retail_price: "-1" }, 1)["retail_price"]).toBeDefined();
});

test("dealer prices require a tier and positive increasing MOQ only when dealer channel is selected", () => {
    expect(validateStep(3, ready(), 1)).toEqual({});
    const data: WizardData = {
        ...ready(),
        sellable_retail: false,
        sellable_dealer: true,
        retail_price: "",
        dealer_rules: [{ tier_id: 1, min_quantity: "-1", unit_price: "90000" }],
    };
    expect(validateStep(3, data, 1)["dealer_rules.0.min_quantity"]).toBeDefined();
    expect(validateStep(3, { ...data, dealer_rules: [] }, 1)["dealer_rules"]).toBeDefined();
    expect(
        validateStep(
            3,
            { ...data, dealer_rules: [{ tier_id: 1, min_quantity: "10", unit_price: "90000" }] },
            1,
        ),
    ).toEqual({});
});

test("stock precision and backend error field routing point to the correct step", () => {
    const errors = validateStep(4, { ...ready(), initial_stock: "1.5" }, 1, 0);
    expect(errors["initial_stock"]).toBeDefined();
    expect(errors["track_inventory"]).toBeDefined();
    expect(errors["warehouse_id"]).toBeDefined();
    expect(stepForField("sku")).toBe(0);
    expect(stepForField("youtube_videos.0")).toBe(1);
    expect(stepForField("variants.0.sku")).toBe(2);
    expect(stepForField("dealer_rules.0.min_quantity")).toBe(3);
    expect(stepForField("variants.0.initial_stock")).toBe(4);
});
