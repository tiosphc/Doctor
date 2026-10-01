import { expect, test } from "bun:test";
import * as XLSX from "xlsx";
import {
    applyDealerPrices,
    dealerPriceSampleRows,
    parseDealerPriceFile,
    parseDealerPricePaste,
    parseDealerPriceSheet,
    previewDealerPrices,
} from "./src/pages/admin/dealerPriceImport";

const tiers = [
    { id: 1, code: "SILVER", name: "Silver", status: "active" as const },
    { id: 2, code: "GOLD", name: "Gold", status: "active" as const },
    { id: 3, code: "DIAMOND", name: "Diamond", status: "active" as const },
    { id: 4, code: "OLD", name: "Old", status: "inactive" as const },
];
const skus = ["DEMO-SKU-01-1", "DEMO-SKU-01-2"];
const current = [
    { tier_id: 1, sku: skus[0]!, min_quantity: "1", unit_price: "215000" },
    { tier_id: 2, sku: skus[0]!, min_quantity: "1", unit_price: "204250" },
];

test("paste previews add and update, then applies without changing Silver", () => {
    const rows = parseDealerPricePaste(
        "Gold DEMO-SKU-01-1 2 200000\nDiamond DEMO-SKU-01-2 1 216000",
    );
    const preview = previewDealerPrices(rows, tiers, skus, current);
    expect(preview.map((row) => row.action)).toEqual(["Cập nhật", "Thêm mới"]);
    expect(preview.every((row) => row.errors.length === 0)).toBe(true);
    expect(applyDealerPrices(current, preview)).toEqual([
        current[0],
        { tier_id: 2, sku: skus[0], min_quantity: "2", unit_price: "200000" },
        { tier_id: 3, sku: skus[1], min_quantity: "1", unit_price: "216000" },
    ]);
    expect(current[1]?.unit_price).toBe("204250");
});

test("invalid SKU, tier, MOQ, price and duplicate pair report their own lines", () => {
    const preview = previewDealerPrices(
        parseDealerPricePaste(
            [
                "Gold OTHER-SKU 1 200000",
                "Platinum DEMO-SKU-01-1 1 200000",
                "Gold DEMO-SKU-01-1 1.5 200000",
                "Gold DEMO-SKU-01-2 1 0",
                "Diamond DEMO-SKU-01-1 1 180000",
                "Diamond DEMO-SKU-01-1 1 190000",
                "Old DEMO-SKU-01-2 1 180000",
                "Gold DEMO-SKU-01-2 1",
            ].join("\n"),
        ),
        tiers,
        skus,
        current,
    );
    expect(preview[0]?.errors.join(" ")).toContain("Không tìm thấy SKU");
    expect(preview[1]?.errors.join(" ")).toContain("Tier Platinum");
    expect(preview[2]?.errors.join(" ")).toContain("MOQ");
    expect(preview[3]?.errors.join(" ")).toContain("Giá VND");
    expect(preview[5]?.errors.join(" ")).toContain("Trùng giá");
    expect(preview[6]?.errors.join(" ")).toContain("không hoạt động");
    expect(preview[7]?.errors.join(" ")).toContain("đúng 4 giá trị");
    expect(() => applyDealerPrices(current, preview)).toThrow();
});

test("sheet parser reports missing fields per row and preserves Excel line numbers", () => {
    const rows = parseDealerPriceSheet([
        ["Tier", "SKU", "MOQ", "Price"],
        ["Gold", "DEMO-SKU-01-1", 1, 200000],
        [],
        ["Diamond", "DEMO-SKU-01-2", 0, ""],
    ]);
    expect(rows.map((row) => row.line)).toEqual([2, 4]);
    expect(previewDealerPrices(rows, tiers, skus, current)[1]?.errors).toEqual([
        "MOQ phải là số nguyên lớn hơn hoặc bằng 1.",
        "Giá là bắt buộc.",
    ]);
    expect(() => parseDealerPriceSheet([["SKU", "Price"]])).toThrow();
});

test("xlsx, legacy xls and csv uploads parse before applying to the form", async () => {
    for (const format of ["xlsx", "xls", "csv"] as const) {
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(
            workbook,
            XLSX.utils.aoa_to_sheet([
                ["Tier", "SKU", "MOQ", "Price"],
                ["Gold", "DEMO-SKU-01-1", 1, 204250],
            ]),
            "Prices",
        );
        const bytes = XLSX.write(workbook, { bookType: format, type: "array" });
        const file = new File([bytes], `dealer-prices.${format}`);
        const rows = await parseDealerPriceFile(file);
        expect(rows).toEqual([
            { line: 2, tier: "Gold", sku: "DEMO-SKU-01-1", moq: "1", price: "204250" },
        ]);
    }
});

test("sample rows use active Gold and Diamond tiers and current product SKUs", () => {
    expect(dealerPriceSampleRows(tiers, skus).map((row) => row.slice(0, 2))).toEqual([
        ["Gold", skus[0]],
        ["Gold", skus[1]],
        ["Diamond", skus[0]],
        ["Diamond", skus[1]],
    ]);
});
