import { apiRequest } from "./api";
import type { DealerTier } from "@/features/dealers/types";
import type { PaginatedResponse, ResourceResponse } from "@/types";
import type {
    Master,
    PriceList,
    PriceListItem,
    Product,
    ProductImage,
    ProductVariant,
    RawPage,
} from "@/types/product";

export type MasterKind = "categories" | "brands" | "units";
export type ProductPricingData = {
    sellable_retail: boolean;
    sellable_dealer: boolean;
    retail_price: string | null;
    variant_retail_prices: Array<{ sku: string; unit_price: string | null }>;
    dealer_rules: Array<{
        tier_id: number | null;
        sku: string;
        min_quantity: string;
        unit_price: string;
    }>;
};
export type CatalogPriceRow = {
    variant_id: number;
    product_id: number;
    product_code: string;
    product_name: string;
    product_image_url: string | null;
    sku: string;
    variant_name: string;
    unit_symbol: string | null;
    status: "active" | "inactive";
    product_status: "draft" | "active" | "inactive";
    sellable: boolean;
    track_inventory?: boolean;
    available_quantity?: string | null;
    tier_id: number | null;
    tier_name: string | null;
    unit_price: string | null;
    minimum_quantity: number | null;
    retail_reference_price: string | null;
};
export type ProductFilters = {
    promotions_only?: boolean;
    gift_filter?: "all" | "gift_capable" | "gift_only" | "normal";
    search?: string | undefined;
    category?: number | undefined;
    brand?: number | undefined;
    status?: string | undefined;
    page?: number | undefined;
    sort?: "newest" | "name" | undefined;
};
export const productKeys = {
    catalog: (filters: ProductFilters) => ["products", filters] as const,
    detail: (slug: string) => ["product", slug] as const,
    admin: (filters: ProductFilters) => ["admin-products", filters] as const,
    masters: (kind: MasterKind) => ["product-master", kind] as const,
    prices: (page: number) => ["retail-price-lists", page] as const,
};
export const productApi = {
    catalogPrices: (
        context: "retail" | "dealer",
        filters: {
            search?: string | undefined;
            status?: string | undefined;
            tier_id?: number | undefined;
            price_status?: "priced" | "unpriced" | undefined;
            warehouse_id?: number | undefined;
            per_page?: number | undefined;
            page?: number | undefined;
        },
    ) => apiRequest<RawPage<CatalogPriceRow>>(`/api/admin/${context}-prices`, { query: filters }),
    updateCatalogRetailPrice: (variantId: number, unitPrice: string) =>
        apiRequest(`/api/admin/retail-prices/${variantId}`, {
            method: "PATCH",
            body: { unit_price: unitPrice },
        }),
    updateCatalogDealerPrice: (
        variantId: number,
        tierId: number,
        unitPrice: string,
        minimumQuantity: number,
    ) =>
        apiRequest(`/api/admin/dealer-prices/${variantId}/${tierId}`, {
            method: "PUT",
            body: { unit_price: unitPrice, minimum_quantity: minimumQuantity },
        }),
    deleteCatalogDealerPrice: (variantId: number, tierId: number) =>
        apiRequest(`/api/admin/dealer-prices/${variantId}/${tierId}`, { method: "DELETE" }),
    filters: () => apiRequest<{ categories: Master[]; brands: Master[] }>("/api/product-filters"),
    catalog: (filters: ProductFilters) =>
        apiRequest<PaginatedResponse<Product>>("/api/products", { query: filters }),
    detail: (slug: string) => apiRequest<ResourceResponse<Product>>(`/api/products/${slug}`),
    adminProducts: (filters: ProductFilters) =>
        apiRequest<RawPage<Product>>("/api/admin/products", { query: filters }),
    adminProduct: (id: number) =>
        apiRequest<ResourceResponse<Product>>(`/api/admin/products/${id}`),
    productPricing: (id: number) =>
        apiRequest<ResourceResponse<ProductPricingData>>(`/api/admin/products/${id}/pricing`),
    updateProductPricing: (id: number, body: ProductPricingData) =>
        apiRequest<ResourceResponse<ProductPricingData>>(`/api/admin/products/${id}/pricing`, {
            method: "PATCH",
            body,
        }),
    createProduct: (body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<Product>>("/api/admin/products", { method: "POST", body }),
    wizardDrafts: () => apiRequest<RawPage<Product>>("/api/admin/product-wizard/drafts"),
    wizardDraft: (id: number) =>
        apiRequest<ResourceResponse<Product>>(`/api/admin/product-wizard/drafts/${id}`),
    createWizardDraft: (body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<Product>>("/api/admin/product-wizard/drafts", {
            method: "POST",
            body,
        }),
    updateWizardDraft: (id: number, body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<Product>>(`/api/admin/product-wizard/drafts/${id}`, {
            method: "PATCH",
            body,
        }),
    completeWizard: (id: number, body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<Product>>(`/api/admin/product-wizard/drafts/${id}/complete`, {
            method: "POST",
            body,
        }),
    skuAvailability: (sku: string) =>
        apiRequest<{ available: boolean }>("/api/admin/product-wizard/sku-availability", {
            query: { sku },
        }),
    dealerTiers: () =>
        apiRequest<{
            data: DealerTier[];
        }>("/api/admin/dealer-tiers"),
    dealerAutoTierPolicy: () =>
        apiRequest<
            ResourceResponse<{
                enabled: boolean;
                version: number;
                revenue_window_months: number;
                next_evaluation_at: string;
            }>
        >("/api/admin/dealer-tiers/auto-policy"),
    setDealerAutoTierPolicy: (enabled: boolean) =>
        apiRequest<
            ResourceResponse<{
                enabled: boolean;
                version: number;
                revenue_window_months: number;
                next_evaluation_at: string;
            }>
        >("/api/admin/dealer-tiers/auto-policy", {
            method: "PUT",
            body: { enabled },
        }),
    updateDealerTier: (
        id: number,
        body: Partial<Pick<DealerTier, "description" | "revenue_threshold">>,
    ) =>
        apiRequest<ResourceResponse<DealerTier>>(`/api/admin/dealer-tiers/${id}`, {
            method: "PATCH",
            body,
        }),
    updateProduct: (id: number, body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<Product>>(`/api/admin/products/${id}`, {
            method: "PATCH",
            body,
        }),
    createVariant: (productId: number, body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<ProductVariant>>(`/api/admin/products/${productId}/variants`, {
            method: "POST",
            body,
        }),
    updateVariant: (productId: number, id: number, body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<ProductVariant>>(
            `/api/admin/products/${productId}/variants/${id}`,
            { method: "PATCH", body },
        ),
    uploadImage: (productId: number, body: FormData) =>
        apiRequest<ResourceResponse<ProductImage>>(`/api/admin/products/${productId}/images`, {
            method: "POST",
            body,
        }),
    updateImage: (productId: number, id: number, body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<ProductImage>>(
            `/api/admin/products/${productId}/images/${id}`,
            {
                method: "PATCH",
                body,
            },
        ),
    deleteImage: (productId: number, id: number) =>
        apiRequest<void>(`/api/admin/products/${productId}/images/${id}`, { method: "DELETE" }),
    masters: (kind: MasterKind, page = 1) =>
        apiRequest<RawPage<Master>>(`/api/admin/product-master/${kind}`, {
            query: { per_page: 100, page },
        }),
    createMaster: (kind: MasterKind, body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<Master>>(`/api/admin/product-master/${kind}`, {
            method: "POST",
            body,
        }),
    updateMaster: (kind: MasterKind, id: number, body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<Master>>(`/api/admin/product-master/${kind}/${id}`, {
            method: "PATCH",
            body,
        }),
    priceLists: (page = 1) =>
        apiRequest<RawPage<PriceList>>("/api/admin/retail-price-lists", { query: { page } }),
    priceList: (id: number) =>
        apiRequest<ResourceResponse<PriceList>>(`/api/admin/retail-price-lists/${id}`),
    createPriceList: (body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<PriceList>>("/api/admin/retail-price-lists", {
            method: "POST",
            body,
        }),
    updatePriceList: (id: number, body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<PriceList>>(`/api/admin/retail-price-lists/${id}`, {
            method: "PATCH",
            body,
        }),
    createPriceItem: (listId: number, body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<PriceListItem>>(
            `/api/admin/retail-price-lists/${listId}/items`,
            { method: "POST", body },
        ),
    updatePriceItem: (listId: number, id: number, body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<PriceListItem>>(
            `/api/admin/retail-price-lists/${listId}/items/${id}`,
            { method: "PATCH", body },
        ),
};
