export type Attribute = { name: string; values: string[] };
export type WizardVariant = {
    sku: string;
    variant_name?: string;
    specifications: Record<string, string>;
    image_id: number | null;
    retail_price_override: string;
    initial_stock: string;
};
export type PriceBreak = { min_quantity: string; unit_price: string };
export type DealerRule = {
    tier_id: number | null;
    sku: string;
    min_quantity: string;
    unit_price: string;
};
export type WizardData = {
    name: string;
    sku: string;
    product_category_id: number | null;
    brand_id: number | null;
    unit_id: number | null;
    sellable_retail: boolean;
    sellable_dealer: boolean;
    can_be_gift: boolean;
    gift_only: boolean;
    description: string;
    youtube_videos: string[];
    has_variants: boolean;
    attributes: Attribute[];
    variants: WizardVariant[];
    retail_price: string;
    retail_breaks: PriceBreak[];
    dealer_rules: DealerRule[];
    track_inventory: boolean;
    warehouse_id: number | null;
    initial_stock: string;
    low_stock_threshold: string;
    weight: string;
    length: string;
    width: string;
    height: string;
    usage_instructions: string;
};

export const emptyWizard: WizardData = {
    name: "",
    sku: "",
    product_category_id: null,
    brand_id: null,
    unit_id: null,
    sellable_retail: true,
    sellable_dealer: false,
    can_be_gift: false,
    gift_only: false,
    description: "",
    youtube_videos: [],
    has_variants: false,
    attributes: [],
    variants: [],
    retail_price: "",
    retail_breaks: [],
    dealer_rules: [],
    track_inventory: false,
    warehouse_id: null,
    initial_stock: "",
    low_stock_threshold: "",
    weight: "",
    length: "",
    width: "",
    height: "",
    usage_instructions: "",
};

export const steps = [
    "Thông tin cơ bản",
    "Hình ảnh",
    "Biến thể",
    "Giá",
    "Kho & vận chuyển",
    "Hướng dẫn sử dụng",
] as const;
export type WizardErrors = Record<string, string>;

const skuPattern = /^[A-Z0-9][A-Z0-9._-]*$/;
const quantityPattern = /^(?:0|[1-9]\d*)$/;
const measurementPattern = /^(?:0|[1-9]\d*)(?:\.\d{1,3})?$/;
const pricePattern = /^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/;
export const normalizeSku = (value: string) => value.trim().toUpperCase();
export const generateVariantSku = (productSku: string, variantName: string) => {
    const suffix = variantName
        .replace(/[đĐ]/g, "d")
        .normalize("NFKD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[^A-Za-z0-9]+/g, "-")
        .replace(/^-|-$/g, "")
        .toUpperCase();
    return suffix ? `${normalizeSku(productSku).slice(0, 60)}-${suffix.slice(0, 39)}` : "";
};
export const validMoney = (value: string) => pricePattern.test(value);
export const validPositiveMoney = (value: string) => validMoney(value) && Number(value) > 0;
const validQuantity = (value: string) => quantityPattern.test(value);
const validInteger = (value: string) => /^[1-9]\d*$/.test(value);

export function parseDealerCsv(
    content: string,
): Array<{ tier: string; sku: string; moq: string; price: string }> {
    const records: string[][] = [];
    let row: string[] = [];
    let cell = "";
    let quoted = false;
    const source = content.replace(/^\uFEFF/, "");
    for (let index = 0; index < source.length; index++) {
        const char = source[index];
        if (char === '"') {
            if (quoted && source[index + 1] === '"') {
                cell += '"';
                index++;
            } else {
                quoted = !quoted;
            }
        } else if (char === "," && !quoted) {
            row.push(cell.trim());
            cell = "";
        } else if ((char === "\n" || char === "\r") && !quoted) {
            row.push(cell.trim());
            if (row.some(Boolean)) records.push(row);
            row = [];
            cell = "";
            if (char === "\r" && source[index + 1] === "\n") index++;
        } else {
            cell += char;
        }
    }
    if (quoted) throw new Error("Dấu nháy CSV chưa được đóng.");
    row.push(cell.trim());
    if (row.some(Boolean)) records.push(row);
    const header = records.shift()?.map((value) => value.toLowerCase());
    if (header?.join(",") !== "tier,sku,moq,price")
        throw new Error("CSV cần đúng bốn cột: Tier, SKU, MOQ, Price.");
    return records.map((record, index) => {
        if (record.length !== 4) throw new Error("Dòng " + (index + 2) + " phải có bốn cột.");
        return { tier: record[0]!, sku: record[1]!, moq: record[2]!, price: record[3]! };
    });
}

export function validYouTube(value: string): boolean {
    try {
        const url = new URL(value);
        if (url.protocol !== "https:") return false;
        if (url.hostname === "youtu.be") return /^\/[A-Za-z0-9_-]+/.test(url.pathname);
        if (
            ![
                "youtube.com",
                "www.youtube.com",
                "m.youtube.com",
                "www.youtube-nocookie.com",
            ].includes(url.hostname)
        )
            return false;
        return (
            (url.pathname === "/watch" && Boolean(url.searchParams.get("v"))) ||
            /^\/(shorts|embed)\/[A-Za-z0-9_-]+/.test(url.pathname)
        );
    } catch {
        return false;
    }
}

export function validateStep(
    step: number,
    data: WizardData,
    imageCount: number,
    _unitPrecision = 3,
): WizardErrors {
    const errors: WizardErrors = {};
    if (step === 0) {
        if (!data.name.trim()) errors["name"] = "Tên sản phẩm là bắt buộc.";
        else if (data.name.trim().length > 255) errors["name"] = "Tên tối đa 255 ký tự.";
        if (!data.sku.trim()) errors["sku"] = "SKU là bắt buộc.";
        else if (!skuPattern.test(normalizeSku(data.sku)) || data.sku.length > 100)
            errors["sku"] = "SKU chỉ gồm chữ, số, dấu chấm, gạch dưới hoặc gạch ngang.";
        if (!data.product_category_id) errors["product_category_id"] = "Vui lòng chọn danh mục.";
        if (!data.unit_id) errors["unit_id"] = "Vui lòng chọn đơn vị.";
        if (!data.sellable_retail && !data.sellable_dealer && !data.gift_only)
            errors["channels"] = "Vui lòng chọn ít nhất một kênh bán.";
    }
    if (step === 1) {
        if (!imageCount) errors["images"] = "Vui lòng tải lên ít nhất một hình ảnh sản phẩm.";
        data.youtube_videos.forEach((url, index) => {
            if (!validYouTube(url))
                errors[`youtube_videos.${index}`] = "Đường dẫn YouTube không hợp lệ.";
        });
    }
    if (step === 2 && data.has_variants) {
        if (!data.variants.length) errors["variants"] = "Thêm ít nhất một biến thể.";
        if (data.variants.length > 100) errors["variants"] = "Tối đa 100 biến thể.";
        const names = new Set<string>();
        const skus = new Set([normalizeSku(data.sku)]);
        data.variants.forEach((variant, index) => {
            const name = variant.variant_name?.trim() ?? "";
            if (!name) errors[`variants.${index}.variant_name`] = "Tên biến thể là bắt buộc.";
            else if (names.has(name.toLocaleLowerCase()))
                errors[`variants.${index}.variant_name`] = "Tên biến thể bị trùng.";
            names.add(name.toLocaleLowerCase());
            const sku = normalizeSku(variant.sku);
            if (!sku || !skuPattern.test(sku) || variant.sku.length > 100)
                errors[`variants.${index}.sku`] = "Không thể tạo SKU từ tên biến thể.";
            else if (skus.has(sku)) errors[`variants.${index}.sku`] = "SKU này đã tồn tại.";
            skus.add(sku);
        });
    }
    if (step === 3) {
        if (data.sellable_retail && !data.gift_only && !validMoney(data.retail_price))
            errors["retail_price"] = "Giá bán lẻ phải từ 0 trở lên.";
        if (data.sellable_dealer && !data.gift_only && !data.dealer_rules.length)
            errors["dealer_rules"] = "Thêm ít nhất một mức giá đại lý.";
        const seen = new Set<string>();
        const skus = data.has_variants
            ? data.variants.map((variant) => normalizeSku(variant.sku))
            : [normalizeSku(data.sku)];
        if (data.sellable_dealer && !data.gift_only)
            data.dealer_rules.forEach((row, index) => {
                if (!row.tier_id) errors[`dealer_rules.${index}.tier_id`] = "Chọn hạng đại lý.";
                const sku = normalizeSku(row.sku);
                if (!skus.includes(sku))
                    errors[`dealer_rules.${index}.sku`] = "Chọn biến thể hợp lệ.";
                const key = `${row.tier_id}:${sku}`;
                if (row.tier_id && sku && seen.has(key))
                    errors[`dealer_rules.${index}.sku`] =
                        "Giá của Tier và biến thể này đã tồn tại.";
                seen.add(key);
                if (!validInteger(row.min_quantity))
                    errors[`dealer_rules.${index}.min_quantity`] = "MOQ phải là số nguyên dương.";
                if (!validPositiveMoney(row.unit_price))
                    errors[`dealer_rules.${index}.unit_price`] = "Giá đại lý phải lớn hơn 0.";
            });
        if (data.sellable_retail && !data.gift_only)
            data.variants.forEach((variant, index) => {
                if (variant.retail_price_override && !validMoney(variant.retail_price_override))
                    errors[`variants.${index}.retail_price_override`] =
                        "Giá biến thể phải từ 0 trở lên.";
            });
    }
    if (step === 4) {
        if (data.gift_only && !data.track_inventory)
            errors["track_inventory"] = "Sản phẩm chỉ tặng cần theo dõi tồn kho.";
        const stockRows = data.has_variants
            ? data.variants.map((variant) => variant.initial_stock)
            : [data.initial_stock];
        const positiveStock = stockRows.some((value) => Number(value) > 0);
        if (positiveStock && !data.track_inventory)
            errors["track_inventory"] = "Bật theo dõi tồn kho trước khi nhập tồn đầu kỳ.";
        if (positiveStock && !data.warehouse_id)
            errors["warehouse_id"] = "Vui lòng chọn kho cho tồn đầu kỳ.";
        stockRows.forEach((value, index) => {
            if (value && !validQuantity(value))
                errors[data.has_variants ? `variants.${index}.initial_stock` : "initial_stock"] =
                    "Tồn đầu kỳ không hợp lệ với độ chính xác của đơn vị.";
        });
        if (data.has_variants && Number(data.initial_stock) > 0)
            errors["initial_stock"] = "Tồn đầu kỳ phải nhập theo từng biến thể.";
        for (const field of [
            "low_stock_threshold",
            "weight",
            "length",
            "width",
            "height",
        ] as const) {
            if (
                data[field] &&
                !(field === "low_stock_threshold"
                    ? validQuantity(data[field])
                    : measurementPattern.test(data[field]))
            )
                errors[field] = "Nhập số không âm, tối đa 3 chữ số thập phân.";
        }
    }
    return errors;
}

export function payload(data: WizardData): Record<string, unknown> {
    return {
        ...data,
        attributes: [],
        retail_breaks: [],
        name: data.name.trim(),
        sku: normalizeSku(data.sku),
        description: data.description || null,
        usage_instructions: data.usage_instructions || null,
        retail_price: data.gift_only ? null : data.retail_price || null,
        initial_stock: data.initial_stock || null,
        low_stock_threshold: data.low_stock_threshold || null,
        weight: data.weight || null,
        length: data.length || null,
        width: data.width || null,
        height: data.height || null,
        variants: (data.has_variants ? data.variants : []).map((variant) => ({
            ...variant,
            sku: normalizeSku(variant.sku),
            variant_name:
                variant.variant_name?.trim() || Object.values(variant.specifications).join(" / "),
            image_id: variant.image_id || null,
            retail_price_override: variant.retail_price_override || null,
            initial_stock: variant.initial_stock || null,
        })),
    };
}

export function stepForField(field: string): number {
    if (/^(name|sku|product_category_id|brand_id|unit_id|channels)/.test(field)) return 0;
    if (/^(images|youtube_videos)/.test(field)) return 1;
    if (/^attributes|^variants\.\d+\.(sku|variant_name|specifications|image_id)/.test(field))
        return 2;
    if (
        /^(retail_price|retail_breaks|dealer_rules)|^variants\.\d+\.retail_price_override/.test(
            field,
        )
    )
        return 3;
    if (
        /^(track_inventory|warehouse_id|initial_stock|low_stock_threshold|weight|length|width|height)|^variants\.\d+\.initial_stock/.test(
            field,
        )
    )
        return 4;
    return 0;
}
