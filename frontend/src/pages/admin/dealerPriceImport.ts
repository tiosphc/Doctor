import { normalizeSku, validPositiveMoney, type DealerRule } from "./productWizard";

export type DealerPriceImportRow = {
    line: number;
    tier: string;
    sku: string;
    moq: string;
    price: string;
    formatError?: string;
};

export type DealerPricePreviewRow = DealerPriceImportRow & {
    tierId: number | null;
    normalizedSku: string;
    action: "Thêm mới" | "Cập nhật" | "-";
    errors: string[];
};

export type DealerPriceTier = {
    id: number;
    code: string;
    name: string;
    status: "active" | "inactive";
};

export function parseDealerPricePaste(content: string): DealerPriceImportRow[] {
    return content.split(/\r?\n/).flatMap((source, index) => {
        const line = source.trim();
        if (!line) return [];
        const values = line.split(/\s+/);
        return [
            {
                line: index + 1,
                tier: values[0] ?? "",
                sku: values[1] ?? "",
                moq: values[2] ?? "",
                price: values[3] ?? "",
                ...(values.length !== 4
                    ? { formatError: "Mỗi dòng phải có đúng 4 giá trị: Tier SKU MOQ Giá." }
                    : {}),
            },
        ];
    });
}

export function parseDealerPriceSheet(cells: unknown[][]): DealerPriceImportRow[] {
    const headerIndex = cells.findIndex((row) => row.some((value) => String(value ?? "").trim()));
    if (headerIndex < 0) throw new Error("File không có dữ liệu.");
    const header = cells[headerIndex]!.map((value) =>
        String(value ?? "")
            .trim()
            .toLowerCase(),
    );
    if (header.slice(0, 4).join(",") !== "tier,sku,moq,price" || header.slice(4).some(Boolean)) {
        throw new Error("File cần đúng bốn cột theo thứ tự: Tier, SKU, MOQ, Price.");
    }
    return cells.slice(headerIndex + 1).flatMap((record, index) => {
        if (!record.some((value) => String(value ?? "").trim())) return [];
        const values = record.map((value) => String(value ?? "").trim());
        return [
            {
                line: headerIndex + index + 2,
                tier: values[0] ?? "",
                sku: values[1] ?? "",
                moq: values[2] ?? "",
                price: values[3] ?? "",
                ...(values.length > 4 && values.slice(4).some(Boolean)
                    ? { formatError: "Dòng có dữ liệu ngoài bốn cột Tier, SKU, MOQ, Price." }
                    : {}),
            },
        ];
    });
}

export async function parseDealerPriceFile(file: File): Promise<DealerPriceImportRow[]> {
    if (!/\.(xlsx|xls|csv)$/i.test(file.name)) {
        throw new Error("Chỉ hỗ trợ file .xlsx, .xls hoặc .csv.");
    }
    if (file.size > 5 * 1024 * 1024) {
        throw new Error("File không được lớn hơn 5 MB.");
    }
    const XLSX = await import("xlsx");
    const workbook = XLSX.read(await file.arrayBuffer(), { type: "array", raw: true });
    const firstSheet = workbook.SheetNames[0];
    if (!firstSheet) throw new Error("File không có trang tính.");
    const sheet = workbook.Sheets[firstSheet];
    if (!sheet) throw new Error("Không đọc được trang tính đầu tiên.");
    const cells = XLSX.utils.sheet_to_json<unknown[]>(sheet, {
        header: 1,
        raw: false,
        defval: "",
        blankrows: true,
    });
    return parseDealerPriceSheet(cells);
}

export function previewDealerPrices(
    rows: DealerPriceImportRow[],
    tiers: DealerPriceTier[],
    productSkus: string[],
    current: DealerRule[],
): DealerPricePreviewRow[] {
    const skus = new Set(productSkus.map(normalizeSku));
    const existing = new Set(current.map((rule) => `${rule.tier_id}:${normalizeSku(rule.sku)}`));
    const imported = new Set<string>();
    return rows.map((row) => {
        const errors: string[] = [];
        const tierName = row.tier.trim().toLocaleLowerCase();
        const tier = tiers.find(
            (item) =>
                item.status === "active" &&
                (item.code.toLocaleLowerCase() === tierName ||
                    item.name.toLocaleLowerCase() === tierName),
        );
        const normalizedSku = normalizeSku(row.sku);
        if (row.formatError) errors.push(row.formatError);
        if (!row.tier.trim()) errors.push("Tier là bắt buộc.");
        else if (!tier) errors.push(`Tier ${row.tier} không tồn tại hoặc không hoạt động.`);
        if (!normalizedSku) errors.push("SKU là bắt buộc.");
        else if (!skus.has(normalizedSku))
            errors.push(`Không tìm thấy SKU ${normalizedSku} trong sản phẩm này.`);
        if (!row.moq.trim()) errors.push("MOQ là bắt buộc.");
        else if (!/^[1-9]\d*$/.test(row.moq))
            errors.push("MOQ phải là số nguyên lớn hơn hoặc bằng 1.");
        if (!row.price.trim()) errors.push("Giá là bắt buộc.");
        else if (!validPositiveMoney(row.price))
            errors.push("Giá VND phải là số lớn hơn 0, tối đa 2 chữ số thập phân.");
        if (tier && normalizedSku) {
            const key = `${tier.id}:${normalizedSku}`;
            if (imported.has(key))
                errors.push(`Trùng giá ${tier.name} cho ${normalizedSku} trong dữ liệu nhập.`);
            imported.add(key);
        }
        const key = `${tier?.id}:${normalizedSku}`;
        return {
            ...row,
            tierId: tier?.id ?? null,
            normalizedSku,
            action: errors.length ? "-" : existing.has(key) ? "Cập nhật" : "Thêm mới",
            errors,
        };
    });
}

export function applyDealerPrices(
    current: DealerRule[],
    rows: DealerPricePreviewRow[],
): DealerRule[] {
    if (!rows.length || rows.some((row) => row.errors.length || row.tierId === null)) {
        throw new Error("Hãy sửa tất cả dòng lỗi trước khi áp dụng.");
    }
    const next = [...current];
    for (const row of rows) {
        const rule: DealerRule = {
            tier_id: row.tierId,
            sku: row.normalizedSku,
            min_quantity: row.moq,
            unit_price: row.price,
        };
        const index = next.findIndex(
            (item) => item.tier_id === row.tierId && normalizeSku(item.sku) === row.normalizedSku,
        );
        if (index >= 0) next[index] = rule;
        else next.push(rule);
    }
    return next;
}

export function dealerPriceSampleRows(tiers: DealerPriceTier[], productSkus: string[]): string[][] {
    const active = tiers.filter((tier) => tier.status === "active");
    const preferred = active.filter((tier) =>
        ["gold", "diamond"].some(
            (name) => tier.code.toLowerCase() === name || tier.name.toLowerCase() === name,
        ),
    );
    const selected = preferred.length ? preferred : active;
    return selected.flatMap((tier, tierIndex) =>
        productSkus
            .filter(Boolean)
            .map((sku, skuIndex) => [
                tier.name.includes(" ") ? tier.code : tier.name,
                normalizeSku(sku),
                "1",
                String(200000 + tierIndex * 25000 + skuIndex * 15000),
            ]),
    );
}
