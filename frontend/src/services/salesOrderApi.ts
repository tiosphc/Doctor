import { apiRequest } from "./api";
import type { ResourceResponse } from "@/types";
import type { RawPage } from "@/types/product";
import type { SalesOrder, SalesOrderDraftInput } from "@/types/salesOrder";

export type SalesOrderFilters = {
    search?: string;
    buyer_user_id?: number;
    warehouse_id?: number;
    order_status?: string;
    payment_status?: string;
    fulfillment_status?: string;
    date_from?: string;
    date_to?: string;
    page?: number;
    per_page?: number;
};

export const salesOrderKeys = {
    list: (filters: SalesOrderFilters) => ["sales-orders", filters] as const,
    detail: (id: number) => ["sales-order", id] as const,
    buyers: (search: string) => ["sales-order-buyers", search] as const,
};

export const salesOrderApi = {
    list: (filters: SalesOrderFilters = {}) =>
        apiRequest<RawPage<SalesOrder>>("/api/admin/sales-orders", { query: filters }),
    detail: (id: number) =>
        apiRequest<ResourceResponse<SalesOrder>>(`/api/admin/sales-orders/${id}`),
    buyers: (search = "") =>
        apiRequest<
            ResourceResponse<{ id: number; name: string; email: string; phone: string | null }[]>
        >("/api/admin/sales-orders/buyers", { query: { search } }),
    create: (body: SalesOrderDraftInput) =>
        apiRequest<ResourceResponse<SalesOrder>>("/api/admin/sales-orders", {
            method: "POST",
            body,
        }),
    reprice: (id: number, operationKey: string) =>
        apiRequest<ResourceResponse<SalesOrder>>(`/api/admin/sales-orders/${id}/reprice`, {
            method: "POST",
            body: { operation_key: operationKey },
        }),
    confirm: (id: number, operationKey: string) =>
        apiRequest<ResourceResponse<SalesOrder>>(`/api/admin/sales-orders/${id}/confirm`, {
            method: "POST",
            body: { operation_key: operationKey },
        }),
    cancel: (id: number, operationKey: string, reason: string) =>
        apiRequest<ResourceResponse<SalesOrder>>(`/api/admin/sales-orders/${id}/cancel`, {
            method: "POST",
            body: { operation_key: operationKey, reason },
        }),
    fulfill: (id: number, operationKey: string, items: { item_id: number; quantity: string }[]) =>
        apiRequest<ResourceResponse<SalesOrder>>(`/api/admin/sales-orders/${id}/fulfill`, {
            method: "POST",
            body: { operation_key: operationKey, items },
        }),
};
