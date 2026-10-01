import { apiDownload, apiRequest } from "@/services/api";
import type { RawPage } from "@/types/product";
import type { PaginatedResponse, ResourceResponse } from "@/types";
import type {
    DealerAccount,
    DealerAccountInput,
    DealerAccountStatus,
    DealerTierResolution,
    DealerAutoTierProgress,
    DealerTierHistory,
    DealerTierOverride,
    DealerCatalogResponse,
    DealerProductResponse,
    DealerQuote,
    DealerQuickOrderReview,
    DealerRecipient,
    DealerShippingAddress,
    DealerShippingAddressInput,
    DealerOrder,
    DealerImport,
    DealerWalletSummary,
    DealerWalletTransaction,
    DealerWalletDeposit,
    AdminDealerWalletRow,
    DealerWalletTopUp,
    AdminDealerWalletTopUp,
} from "./types";

export type DealerOrderFilters = {
    page: number;
    search?: string;
    order_status?: string;
    status_group?: "pending" | "active" | "completed" | "cancelled";
    payment_status?: string;
    warehouse_id?: number;
    order_source?: "quick_order" | "dealer_excel";
    date_from?: string;
    date_to?: string;
};

export const dealerKeys = {
    mine: (userId?: number) => ["dealer-accounts-mine", userId] as const,
    detail: (userId: number | undefined, id: number) => ["dealer-account", userId, id] as const,
    adminList: (filters: object) => ["admin-dealers", filters] as const,
    adminDetail: (id: number) => ["admin-dealer", id] as const,
    tier: (userId: number | undefined, id: number) => ["dealer-tier", userId, id] as const,
    autoTier: (userId: number | undefined, id: number) => ["dealer-auto-tier", userId, id] as const,
    adminTier: (id: number) => ["admin-dealer-tier", id] as const,
    adminAutoTier: (id: number) => ["admin-dealer-auto-tier", id] as const,
    history: (id: number) => ["admin-dealer-tier-history", id] as const,
    overrides: (id: number) => ["admin-dealer-tier-overrides", id] as const,
    products: (
        userId: number | undefined,
        accountId: number,
        search: string,
        page: number,
        warehouseId?: number | null,
    ) => ["dealer-products", userId, accountId, search, page, warehouseId] as const,
    product: (
        userId: number | undefined,
        accountId: number,
        slug: string,
        warehouseId?: number | null,
    ) => ["dealer-product", userId, accountId, slug, warehouseId] as const,
    orders: (userId: number | undefined, accountId: number, filters: number | DealerOrderFilters) =>
        ["dealer-orders", userId, accountId, filters] as const,
    order: (userId: number | undefined, accountId: number, orderId: number) =>
        ["dealer-order", userId, accountId, orderId] as const,
    wallet: (userId: number | undefined, accountId: number) =>
        ["dealer-wallet", userId, accountId] as const,
    walletTransactions: (userId: number | undefined, accountId: number, page: number) =>
        ["dealer-wallet-transactions", userId, accountId, page] as const,
    walletTopUps: (userId: number | undefined, accountId: number, page: number) =>
        ["dealer-wallet-top-ups", userId, accountId, page] as const,
    walletTopUp: (userId: number | undefined, accountId: number, topUpId: number) =>
        ["dealer-wallet-top-up", userId, accountId, topUpId] as const,
    adminTopUps: (filters: object) => ["admin-dealer-wallet-top-ups", filters] as const,
};

export const dealerApi = {
    provinces: () =>
        apiRequest<{ data: { code: string; name: string }[] }>("/api/administrative/provinces"),
    wards: (provinceCode: string) =>
        apiRequest<{ data: { code: string; name: string }[] }>("/api/administrative/wards", {
            query: { province_code: provinceCode },
        }),
    addresses: (id: number) =>
        apiRequest<{ data: DealerShippingAddress[] }>(
            `/api/dealer/accounts/${id}/shipping-addresses`,
        ),
    createAddress: (
        id: number,
        body: Omit<DealerShippingAddressInput, "is_default"> & { is_default?: boolean },
    ) =>
        apiRequest<{ data: DealerShippingAddress }>(
            `/api/dealer/accounts/${id}/shipping-addresses`,
            { method: "POST", body },
        ),
    mine: () => apiRequest<ResourceResponse<DealerAccount[]>>("/api/dealer/accounts"),
    detail: (id: number) =>
        apiRequest<ResourceResponse<DealerAccount>>(`/api/dealer/accounts/${id}`),
    tier: (id: number) =>
        apiRequest<ResourceResponse<DealerTierResolution>>(`/api/dealer/accounts/${id}/tier`),
    autoTier: (id: number) =>
        apiRequest<ResourceResponse<DealerAutoTierProgress>>(
            `/api/dealer/accounts/${id}/auto-tier`,
        ),
    products: (
        id: number,
        filters: {
            search?: string;
            page?: number;
            per_page?: number;
        },
    ) =>
        apiRequest<DealerCatalogResponse>(`/api/dealer/accounts/${id}/products`, {
            query: filters,
        }),
    product: (id: number, slug: string) =>
        apiRequest<DealerProductResponse>(
            `/api/dealer/accounts/${id}/products/${encodeURIComponent(slug)}`,
            {},
        ),
    quote: (id: number, productVariantId: number, quantity: string) =>
        apiRequest<ResourceResponse<DealerQuote>>(`/api/dealer/accounts/${id}/pricing/quote`, {
            method: "POST",
            body: { product_variant_id: productVariantId, quantity },
        }),
    quickOrderReview: (
        id: number,
        items: { product_variant_id: number; quantity: string }[],
        shipping: { shipping_address_id: number } | Partial<DealerRecipient>,
    ) =>
        apiRequest<ResourceResponse<DealerQuickOrderReview>>(
            `/api/dealer/accounts/${id}/quick-order/review`,
            {
                method: "POST",
                body: { items, ...shipping },
            },
        ),
    quickOrderSubmit: (
        id: number,
        body: DealerRecipient & {
            save_address?: boolean;
            operation_key: string;
            review_fingerprint: string;
            items: { product_variant_id: number; quantity: string }[];
        },
    ) =>
        apiRequest<ResourceResponse<DealerOrder>>(`/api/dealer/accounts/${id}/quick-order`, {
            method: "POST",
            body,
        }),
    orders: (id: number, pageOrFilters: number | DealerOrderFilters) =>
        apiRequest<
            PaginatedResponse<DealerOrder> & {
                status_counts: Record<string, number>;
                warehouses: { id: number; code: string; name: string }[];
            }
        >(`/api/dealer/accounts/${id}/orders`, {
            query: typeof pageOrFilters === "number" ? { page: pageOrFilters } : pageOrFilters,
        }),
    order: (id: number, orderId: number) =>
        apiRequest<ResourceResponse<DealerOrder>>(`/api/dealer/accounts/${id}/orders/${orderId}`),
    cancelOrder: (id: number, orderId: number, operationKey: string) =>
        apiRequest<ResourceResponse<DealerOrder>>(
            `/api/dealer/accounts/${id}/orders/${orderId}/cancel`,
            { method: "POST", body: { operation_key: operationKey } },
        ),
    wallet: (id: number) =>
        apiRequest<ResourceResponse<DealerWalletSummary>>(`/api/dealer/accounts/${id}/wallet`),
    walletTransactions: (id: number, page: number) =>
        apiRequest<{ data: DealerWalletTransaction[]; current_page: number; last_page: number }>(
            `/api/dealer/accounts/${id}/wallet/transactions`,
            { query: { page } },
        ),
    walletTopUps: (id: number, page: number) =>
        apiRequest<RawPage<DealerWalletTopUp>>(`/api/dealer/accounts/${id}/wallet/top-ups`, {
            query: { page },
        }),
    walletTopUp: (id: number, topUpId: number) =>
        apiRequest<ResourceResponse<DealerWalletTopUp>>(
            `/api/dealer/accounts/${id}/wallet/top-ups/${topUpId}`,
        ),
    createWalletTopUp: (id: number, amount: number, operationKey: string) =>
        apiRequest<ResourceResponse<DealerWalletTopUp>>(
            `/api/dealer/accounts/${id}/wallet/top-ups`,
            { method: "POST", body: { amount, operation_key: operationKey } },
        ),
    refreshWalletTopUp: (id: number, topUpId: number) =>
        apiRequest<ResourceResponse<DealerWalletTopUp>>(
            `/api/dealer/accounts/${id}/wallet/top-ups/${topUpId}/refresh`,
            { method: "POST" },
        ),
    importTemplate: (id: number) =>
        apiDownload(`/api/dealer/accounts/${id}/order-imports/template`),
    imports: (id: number, page: number) =>
        apiRequest<RawPage<DealerImport>>(`/api/dealer/accounts/${id}/order-imports`, {
            query: { page },
        }),
    importDetail: (id: number, importId: number) =>
        apiRequest<ResourceResponse<DealerImport>>(
            `/api/dealer/accounts/${id}/order-imports/${importId}`,
        ),
    importUpload: (id: number, file: File) => {
        const body = new FormData();
        body.append("file", file);
        return apiRequest<ResourceResponse<DealerImport>>(
            `/api/dealer/accounts/${id}/order-imports`,
            {
                method: "POST",
                body,
            },
        );
    },
    importRevalidate: (id: number, importId: number) =>
        apiRequest<ResourceResponse<DealerImport>>(
            `/api/dealer/accounts/${id}/order-imports/${importId}/revalidate`,
            {
                method: "POST",
            },
        ),
    importConfirm: (id: number, importId: number, operationKey: string, fingerprint: string) =>
        apiRequest<ResourceResponse<DealerImport>>(
            `/api/dealer/accounts/${id}/order-imports/${importId}/confirm`,
            {
                method: "POST",
                body: { operation_key: operationKey, preview_fingerprint: fingerprint },
            },
        ),
    adminList: (filters: { status?: DealerAccountStatus | ""; search?: string; page?: number }) =>
        apiRequest<PaginatedResponse<DealerAccount>>("/api/admin/dealers", { query: filters }),
    adminDetail: (id: number) =>
        apiRequest<ResourceResponse<DealerAccount>>(`/api/admin/dealers/${id}`),
    adminWallet: (id: number) =>
        apiRequest<ResourceResponse<DealerWalletSummary>>(`/api/admin/dealers/${id}/wallet`),
    adminWallets: (filters: {
        search?: string;
        tier_id?: number | "";
        status?: string;
        page?: number;
    }) =>
        apiRequest<RawPage<AdminDealerWalletRow>>("/api/admin/dealer-wallets", { query: filters }),
    adminAllWalletTransactions: (filters: {
        search?: string;
        dealer_id?: number | "";
        type?: string;
        from?: string;
        to?: string;
        page?: number;
    }) =>
        apiRequest<
            RawPage<DealerWalletTransaction> & {
                summary: {
                    total_balance: string;
                    total_deposited: string;
                    total_spent: string;
                    total_refunded: string;
                };
            }
        >("/api/admin/dealer-wallet-transactions", {
            query: filters,
        }),
    adminTopUps: (filters: {
        status?: string;
        search?: string;
        from?: string;
        to?: string;
        page?: number;
    }) =>
        apiRequest<RawPage<AdminDealerWalletTopUp>>("/api/admin/dealer-wallet-top-ups", {
            query: filters,
        }),
    adminWalletTransactions: (
        id: number,
        filters: { search?: string; type?: string; from?: string; to?: string; page?: number } = {},
    ) =>
        apiRequest<RawPage<DealerWalletTransaction>>(
            `/api/admin/dealers/${id}/wallet/transactions`,
            { query: filters },
        ),
    adminWalletDeposits: (id: number, filters: { search?: string; page?: number } = {}) =>
        apiRequest<RawPage<DealerWalletDeposit>>(`/api/admin/dealers/${id}/wallet/deposits`, {
            query: filters,
        }),
    adminWalletDeposit: (
        id: number,
        body: {
            amount: string;
            method: "bank_transfer" | "cash" | "other_manual";
            external_reference?: string;
            note?: string;
            operation_key: string;
        },
    ) =>
        apiRequest<ResourceResponse<DealerWalletSummary & { deposit_code: string }>>(
            `/api/admin/dealers/${id}/wallet/deposits`,
            { method: "POST", body },
        ),
    adminTier: (id: number) =>
        apiRequest<ResourceResponse<DealerTierResolution>>(`/api/admin/dealers/${id}/tier`),
    adminAutoTier: (id: number) =>
        apiRequest<ResourceResponse<DealerAutoTierProgress>>(`/api/admin/dealers/${id}/auto-tier`),
    tierHistory: (id: number) =>
        apiRequest<PaginatedResponse<DealerTierHistory>>(`/api/admin/dealers/${id}/tier-history`),
    tierOverrides: (id: number) =>
        apiRequest<PaginatedResponse<DealerTierOverride>>(
            `/api/admin/dealers/${id}/tier-overrides`,
        ),
    changeTier: (id: number, body: { tier_id: number; reason: string; operation_key: string }) =>
        apiRequest<ResourceResponse<DealerTierHistory>>(`/api/admin/dealers/${id}/tier/change`, {
            method: "POST",
            body,
        }),
    createTierOverride: (
        id: number,
        body: { tier_id: number; starts_at: string; ends_at: string | null; reason: string },
    ) =>
        apiRequest<ResourceResponse<DealerTierOverride>>(
            `/api/admin/dealers/${id}/tier-overrides`,
            { method: "POST", body },
        ),
    cancelTierOverride: (id: number, overrideId: number) =>
        apiRequest<ResourceResponse<DealerTierOverride>>(
            `/api/admin/dealers/${id}/tier-overrides/${overrideId}/cancel`,
            { method: "POST" },
        ),
    update: (id: number, body: DealerAccountInput) =>
        apiRequest<ResourceResponse<DealerAccount>>(`/api/admin/dealers/${id}`, {
            method: "PATCH",
            body,
        }),
    transition: (id: number, action: "activate" | "suspend" | "inactivate") =>
        apiRequest<ResourceResponse<DealerAccount>>(`/api/admin/dealers/${id}/${action}`, {
            method: "POST",
        }),
};
