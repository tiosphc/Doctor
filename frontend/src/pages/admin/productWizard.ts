export type Attribute = { name: string; values: string[] };
export type WizardVariant = {
    sku: string;
    specifications: Record<string, string>;
    image_id: number | null;
    retail_price_override: string;
    initial_stock: string;
};
export type PriceBreak = { min_quantity: string; unit_price: string };
export type DealerRule = { tier_id: number | null; min_quantity: string; unit_price: string };
export type WizardData = {
    name: string;
    sku: string;
    product_category_id: number | null;
    brand_id: number | null;
    unit_id: number | null;
    sellable_retail: boolean;
    sellable_dealer: boolean;
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
    "Giá & MOQ",
    "Kho & vận chuyển",
    "Hướng dẫn sử dụng",
] as const;
export type WizardErrors = Record<string, string>;

const skuPattern = /^[A-Z0-9][A-Z0-9._-]*$/;
const quantityPattern = /^(?:0|[1-9]\d*)(?:\.\d{1,3})?$/;
const pricePattern = /^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/;
export const normalizeSku = (value: string) => value.trim().toUpperCase();
export const validMoney = (value: string) => pricePattern.test(value) && Number(value) > 0;
const validQuantity = (value: string) => quantityPattern.test(value);
const validInteger = (value: string) => /^[1-9]\d*$/.test(value);

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

export function combinations(attributes: Attribute[]): Record<string, string>[] {
    return attributes.reduce<Record<string, string>[]>(
        (rows, attribute) =>
            rows.flatMap((row) =>
                attribute.values.map((value) => ({
                    ...row,
                    [attribute.name.trim()]: value.trim(),
                })),
            ),
        [{}],
    );
}

export function generateVariants(data: WizardData): WizardVariant[] {
    const existing = new Map(
        data.variants.map((variant) => [JSON.stringify(variant.specifications), variant]),
    );
    return combinations(data.attributes).map((specifications) => {
        const key = JSON.stringify(specifications);
        const suffix = Object.values(specifications)
            .join("-")
            .normalize("NFKD")
            .replace(/[\u0300-\u036f]/g, "")
            .replace(/[^A-Za-z0-9]+/g, "-")
            .replace(/^-|-$/g, "")
            .toUpperCase();
        return (
            existing.get(key) ?? {
                sku: `${normalizeSku(data.sku)}-${suffix}`.slice(0, 100),
                specifications,
                image_id: null,
                retail_price_override: "",
                initial_stock: "",
            }
        );
    });
}

export function validateStep(
    step: number,
    data: WizardData,
    imageCount: number,
    unitPrecision = 3,
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
        if (!data.sellable_retail && !data.sellable_dealer)
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
        if (!data.attributes.length) errors["attributes"] = "Thêm ít nhất một thuộc tính biến thể.";
        const names = new Set<string>();
        data.attributes.forEach((attribute, index) => {
            const name = attribute.name.trim().toLocaleLowerCase();
            if (!name) errors[`attributes.${index}.name`] = "Tên thuộc tính là bắt buộc.";
            else if (names.has(name))
                errors[`attributes.${index}.name`] = "Tên thuộc tính biến thể bị trùng.";
            names.add(name);
            if (!attribute.values.length)
                errors[`attributes.${index}.values`] = "Thêm ít nhất một giá trị.";
            const values = new Set<string>();
            attribute.values.forEach((value, valueIndex) => {
                const key = value.trim().toLocaleLowerCase();
                if (!key)
                    errors[`attributes.${index}.values.${valueIndex}`] = "Giá trị là bắt buộc.";
                else if (values.has(key))
                    errors[`attributes.${index}.values.${valueIndex}`] =
                        "Giá trị biến thể bị trùng.";
                values.add(key);
            });
        });
        const expected = combinations(data.attributes);
        if (expected.length > 100) errors["variants"] = "Tối đa 100 biến thể.";
        else if (
            data.variants.length !== expected.length ||
            !expected.every((row) =>
                data.variants.some(
                    (variant) => JSON.stringify(variant.specifications) === JSON.stringify(row),
                ),
            )
        )
            errors["variants"] = "Hãy tạo đầy đủ các tổ hợp biến thể.";
        const skus = new Set([normalizeSku(data.sku)]);
        data.variants.forEach((variant, index) => {
            const sku = normalizeSku(variant.sku);
            if (!skuPattern.test(sku) || variant.sku.length > 100)
                errors[`variants.${index}.sku`] = "SKU biến thể không hợp lệ.";
            else if (skus.has(sku)) errors[`variants.${index}.sku`] = "SKU này đã tồn tại.";
            skus.add(sku);
        });
    }
    if (step === 3) {
        if (data.sellable_retail && !validMoney(data.retail_price))
            errors["retail_price"] = "Giá bán lẻ phải lớn hơn 0.";
        let last = 1;
        data.retail_breaks.forEach((row, index) => {
            const minimum = Number(row.min_quantity);
            if (!validInteger(row.min_quantity) || minimum <= last)
                errors[`retail_breaks.${index}.min_quantity`] =
                    "Mức số lượng phải tăng dần, không trùng hoặc chồng lấn.";
            if (!validMoney(row.unit_price))
                errors[`retail_breaks.${index}.unit_price`] = "Giá phải lớn hơn 0.";
            last = minimum;
        });
        if (data.sellable_dealer && !data.dealer_rules.length)
            errors["dealer_rules"] = "Thêm ít nhất một mức giá đại lý.";
        const minimums = new Map<number, number>();
        data.dealer_rules.forEach((row, index) => {
            if (!row.tier_id) errors[`dealer_rules.${index}.tier_id`] = "Chọn hạng đại lý.";
            const minimum = Number(row.min_quantity);
            if (
                !validInteger(row.min_quantity) ||
                (row.tier_id && minimum <= (minimums.get(row.tier_id) ?? 0))
            )
                errors[`dealer_rules.${index}.min_quantity`] =
                    "MOQ phải là số nguyên dương và tăng dần theo từng hạng.";
            if (!validMoney(row.unit_price))
                errors[`dealer_rules.${index}.unit_price`] = "Giá đại lý phải lớn hơn 0.";
            if (row.tier_id) minimums.set(row.tier_id, minimum);
        });
        data.variants.forEach((variant, index) => {
            if (variant.retail_price_override && !validMoney(variant.retail_price_override))
                errors[`variants.${index}.retail_price_override`] = "Giá biến thể phải lớn hơn 0.";
        });
    }
    if (step === 4) {
        const stockRows = data.has_variants
            ? data.variants.map((variant) => variant.initial_stock)
            : [data.initial_stock];
        const positiveStock = stockRows.some((value) => Number(value) > 0);
        if (positiveStock && !data.track_inventory)
            errors["track_inventory"] = "Bật theo dõi tồn kho trước khi nhập tồn đầu kỳ.";
        if (positiveStock && !data.warehouse_id)
            errors["warehouse_id"] = "Vui lòng chọn kho cho tồn đầu kỳ.";
        stockRows.forEach((value, index) => {
            if (
                value &&
                (!validQuantity(value) || (value.split(".")[1]?.length ?? 0) > unitPrecision)
            )
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
            if (data[field] && !validQuantity(data[field]))
                errors[field] = "Nhập số không âm, tối đa 3 chữ số thập phân.";
        }
    }
    return errors;
}

export function payload(data: WizardData): Record<string, unknown> {
    return {
        ...data,
        name: data.name.trim(),
        sku: normalizeSku(data.sku),
        description: data.description || null,
        usage_instructions: data.usage_instructions || null,
        retail_price: data.retail_price || null,
        initial_stock: data.initial_stock || null,
        low_stock_threshold: data.low_stock_threshold || null,
        weight: data.weight || null,
        length: data.length || null,
        width: data.width || null,
        height: data.height || null,
        variants: data.variants.map((variant) => ({
            ...variant,
            sku: normalizeSku(variant.sku),
            image_id: variant.image_id || null,
            retail_price_override: variant.retail_price_override || null,
            initial_stock: variant.initial_stock || null,
        })),
    };
}

export function stepForField(field: string): number {
    if (/^(name|sku|product_category_id|brand_id|unit_id|channels)/.test(field)) return 0;
    if (/^(images|youtube_videos)/.test(field)) return 1;
    if (/^attributes|^variants\.\d+\.(sku|specifications)/.test(field)) return 2;
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
