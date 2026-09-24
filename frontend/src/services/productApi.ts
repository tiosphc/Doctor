import { apiRequest } from "./api";
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
export type ProductFilters = {
    search?: string | undefined;
    category?: number | undefined;
    brand?: number | undefined;
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
    filters: () => apiRequest<{ categories: Master[]; brands: Master[] }>("/api/product-filters"),
    catalog: (filters: ProductFilters) =>
        apiRequest<PaginatedResponse<Product>>("/api/products", { query: filters }),
    detail: (slug: string) => apiRequest<ResourceResponse<Product>>(`/api/products/${slug}`),
    adminProducts: (filters: ProductFilters) =>
        apiRequest<RawPage<Product>>("/api/admin/products", { query: filters }),
    adminProduct: (id: number) =>
        apiRequest<ResourceResponse<Product>>(`/api/admin/products/${id}`),
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
            data: Array<{ id: number; code: string; name: string; status: "active" | "inactive" }>;
        }>("/api/admin/dealer-tiers"),
    createDealerTier: (body: { code: string; name: string }) =>
        apiRequest<ResourceResponse<{ id: number; code: string; name: string; status: string }>>(
            "/api/admin/dealer-tiers",
            { method: "POST", body },
        ),
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
