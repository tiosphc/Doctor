import type { CatalogPriceRow } from "@/services/productApi";

export type CatalogVariantGroup = {
    id: number;
    sku: string;
    name: string;
    unit: string | null;
    retailReference: string | null;
    prices: CatalogPriceRow[];
};

export type CatalogProductGroup = {
    id: number;
    code: string;
    name: string;
    imageUrl: string | null;
    variants: CatalogVariantGroup[];
    pricedCount: number;
    missingCount: number;
    minPrice: number | null;
    maxPrice: number | null;
};

export type DealerPriceMatrix = {
    tiers: Array<{ id: number; name: string | null }>;
    rows: Array<{
        variant: CatalogVariantGroup;
        pricesByTier: Map<number, CatalogPriceRow>;
    }>;
};

export function buildDealerPriceMatrix(variants: CatalogVariantGroup[]): DealerPriceMatrix {
    const tiers = new Map<number, { id: number; name: string | null }>();
    const rows = variants.map((variant) => {
        const pricesByTier = new Map<number, CatalogPriceRow>();
        for (const price of variant.prices) {
            if (price.tier_id === null) continue;
            tiers.set(price.tier_id, { id: price.tier_id, name: price.tier_name });
            pricesByTier.set(price.tier_id, price);
        }
        return { variant, pricesByTier };
    });

    return { tiers: Array.from(tiers.values()), rows };
}

/** The API paginates variants, so groups contain only variants on the current page. */
export function groupCatalogPriceRows(rows: CatalogPriceRow[]): CatalogProductGroup[] {
    const products = new Map<number, CatalogProductGroup>();
    const variants = new Map<number, CatalogVariantGroup>();

    for (const row of rows) {
        let product = products.get(row.product_id);
        if (!product) {
            product = {
                id: row.product_id,
                code: row.product_code,
                name: row.product_name,
                imageUrl: row.product_image_url,
                variants: [],
                pricedCount: 0,
                missingCount: 0,
                minPrice: null,
                maxPrice: null,
            };
            products.set(row.product_id, product);
        }

        let variant = variants.get(row.variant_id);
        if (!variant) {
            variant = {
                id: row.variant_id,
                sku: row.sku,
                name: row.variant_name,
                unit: row.unit_symbol,
                retailReference: row.retail_reference_price,
                prices: [],
            };
            variants.set(row.variant_id, variant);
            product.variants.push(variant);
        }

        variant.prices.push(row);
        if (row.unit_price === null) {
            product.missingCount += 1;
        } else {
            product.pricedCount += 1;
            const value = Number(row.unit_price);
            product.minPrice =
                product.minPrice === null ? value : Math.min(product.minPrice, value);
            product.maxPrice =
                product.maxPrice === null ? value : Math.max(product.maxPrice, value);
        }
    }

    return Array.from(products.values());
}
