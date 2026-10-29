import { formatProductQuantity, isPositiveProductQuantity } from "@/lib/productQuantity";
import type { DealerProduct, DealerQuickOrderLine } from "./types";

export type QuickOrderRow = {
    product_variant_id: number;
    sku: string;
    product_name: string;
    variant_name: string;
    image_url: string | null;
    quantity: string;
    minimum_quantity: string;
    unit_price: string | null;
    unit_symbol: string | null;
    available_quantity: string | null;
    errors: string[];
};

export function appendUniqueRows(
    current: QuickOrderRow[],
    candidates: QuickOrderRow[],
    limit = 50,
): { rows: QuickOrderRow[]; added: number; exceedsLimit: boolean } {
    const seen = new Set(current.map((row) => row.product_variant_id));
    const additions = candidates.filter((row) => {
        if (seen.has(row.product_variant_id)) return false;
        seen.add(row.product_variant_id);
        return true;
    });
    if (current.length + additions.length > limit) {
        return { rows: current, added: 0, exceedsLimit: true };
    }
    return { rows: [...current, ...additions], added: additions.length, exceedsLimit: false };
}

export function matchingVariants(product: DealerProduct, term: string): DealerProduct["variants"] {
    const needle = term.trim().toLocaleLowerCase();
    if (!needle || `${product.name} ${product.product_code}`.toLocaleLowerCase().includes(needle)) {
        return product.variants;
    }
    return product.variants.filter((variant) =>
        `${variant.sku} ${variant.variant_name} ${JSON.stringify(variant.specifications ?? {})}`
            .toLocaleLowerCase()
            .includes(needle),
    );
}

export function catalogRow(
    product: DealerProduct,
    variant: DealerProduct["variants"][number],
): QuickOrderRow {
    const image =
        product.images.find((item) => item.product_variant_id === variant.id) ??
        product.images.find((item) => item.is_primary) ??
        product.images[0];
    return {
        product_variant_id: variant.id,
        sku: variant.sku,
        product_name: product.name,
        variant_name: variant.variant_name,
        image_url: image?.url ?? null,
        quantity: formatProductQuantity(variant.dealer_price.minimum_quantity),
        minimum_quantity: variant.dealer_price.minimum_quantity,
        unit_price: variant.dealer_price.unit_price,
        unit_symbol: variant.unit_symbol,
        available_quantity: null,
        errors: [],
    };
}

export function rowQuantityError(row: QuickOrderRow): string | null {
    if (!isPositiveProductQuantity(row.quantity)) return "Số lượng phải là số nguyên dương.";
    if (Number(row.quantity) < Number(row.minimum_quantity)) {
        return `Số lượng tối thiểu là ${formatProductQuantity(row.minimum_quantity)}.`;
    }
    if (row.available_quantity !== null && Number(row.quantity) > Number(row.available_quantity)) {
        return `Chỉ còn ${formatProductQuantity(row.available_quantity)} sản phẩm khả dụng.`;
    }
    return null;
}

export function reviewRow(line: DealerQuickOrderLine, previous?: QuickOrderRow): QuickOrderRow {
    return {
        product_variant_id: line.product_variant_id,
        sku: line.sku ?? previous?.sku ?? "—",
        product_name: line.product_name ?? previous?.product_name ?? "Sản phẩm không còn khả dụng",
        variant_name: line.variant_name ?? previous?.variant_name ?? "",
        image_url: line.image_url ?? previous?.image_url ?? null,
        quantity: formatProductQuantity(line.quantity),
        minimum_quantity: line.minimum_quantity ?? previous?.minimum_quantity ?? "1",
        unit_price: line.unit_price,
        unit_symbol: line.unit_symbol,
        available_quantity: line.available_quantity,
        errors: line.errors,
    };
}
