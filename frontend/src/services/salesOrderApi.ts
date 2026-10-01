import { apiRequest } from "./api";
import type { ResourceResponse } from "@/types";
import type { RawPage } from "@/types/product";
import type {
    OrderPayments,
    OrderRefund,
    OrderReturn,
    ReturnableItem,
    RecordedPayment,
    SalesOrder,
    SalesOrderDraftInput,
} from "@/types/salesOrder";

export type SalesOrderFilters = {
    search?: string;
    buyer_user_id?: number;
    warehouse_id?: number;
    dealer_account_id?: number;
    sales_channel?: "retail" | "dealer" | "";
    order_source?: "admin" | "cart" | "quick_order" | "dealer_excel" | "";
    order_status?: string;
    payment_status?: string;
    payment_method?: string;
    fulfillment_status?: string;
    date_from?: string;
    date_to?: string;
    page?: number;
    per_page?: number;
};

export type RetailBuyer = { id: number; name: string; email: string; phone: string | null };
export type RetailBuyerDetail = RetailBuyer & {
    last_shipping: Pick<
        SalesOrder,
        | "recipient_name"
        | "recipient_phone"
        | "recipient_email"
        | "shipping_address_line1"
        | "shipping_address_line2"
        | "shipping_district"
        | "shipping_city"
        | "shipping_province"
        | "shipping_country"
        | "shipping_postal_code"
        | "delivery_note"
    > | null;
};
export type RetailOrderPreview = {
    items: {
        sku: string;
        product_name: string;
        variant_name: string;
        unit_name: string;
        quantity: string;
        unit_price: string;
        line_total: string;
        available_quantity: string;
        insufficient_stock: boolean;
    }[];
    subtotal: string;
    discount_total: string;
    shipping_total: string;
    grand_total: string;
};

export const salesOrderKeys = {
    list: (filters: SalesOrderFilters) => ["sales-orders", filters] as const,
    detail: (id: number) => ["sales-order", id] as const,
    payments: (id: number) => ["sales-order-payments", id] as const,
    buyers: (search: string) => ["sales-order-buyers", search] as const,
};

export const salesOrderApi = {
    list: (filters: SalesOrderFilters = {}) =>
        apiRequest<RawPage<SalesOrder>>("/api/admin/sales-orders", { query: filters }),
    detail: (id: number) =>
        apiRequest<ResourceResponse<SalesOrder>>(`/api/admin/sales-orders/${id}`),
    buyers: (search = "") =>
        apiRequest<ResourceResponse<RetailBuyer[]>>("/api/admin/sales-orders/buyers", {
            query: { search },
        }),
    buyer: (id: number) =>
        apiRequest<ResourceResponse<RetailBuyerDetail>>(`/api/admin/sales-orders/buyers/${id}`),
    createBuyer: (body: { name: string; email: string; phone: string }) =>
        apiRequest<ResourceResponse<RetailBuyer>>("/api/admin/sales-orders/buyers", {
            method: "POST",
            body,
        }),
    previewRetail: (body: { warehouse_id: number; items: { sku: string; quantity: string }[] }) =>
        apiRequest<ResourceResponse<RetailOrderPreview>>("/api/admin/sales-orders/retail-preview", {
            method: "POST",
            body,
        }),
    create: (body: SalesOrderDraftInput) =>
        apiRequest<ResourceResponse<SalesOrder>>("/api/admin/sales-orders", {
            method: "POST",
            body,
        }),
    updateDraft: (id: number, body: SalesOrderDraftInput) =>
        apiRequest<ResourceResponse<SalesOrder>>(`/api/admin/sales-orders/${id}/draft`, {
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
    advance: (
        id: number,
        operationKey: string,
        target: "preparing" | "shipping" | "delivered" | "completed",
        note?: string,
    ) =>
        apiRequest<ResourceResponse<SalesOrder>>(`/api/admin/sales-orders/${id}/advance`, {
            method: "POST",
            body: { operation_key: operationKey, target, note },
        }),
    payments: (id: number) =>
        apiRequest<ResourceResponse<OrderPayments>>(`/api/admin/sales-orders/${id}/payments`),
    refunds: (id: number) =>
        apiRequest<ResourceResponse<{ summary: OrderPayments["summary"]; refunds: OrderRefund[] }>>(
            `/api/admin/sales-orders/${id}/refunds`,
        ),
    returns: (id: number) =>
        apiRequest<ResourceResponse<OrderReturn[]>>(`/api/admin/sales-orders/${id}/returns`),
    pendingReturns: () =>
        apiRequest<
            ResourceResponse<
                {
                    id: number;
                    return_code: string;
                    sales_order_id: number;
                    order_code: string;
                    sales_channel: string;
                    recipient_name: string;
                    requested_by: string | null;
                    requested_at: string;
                }[]
            >
        >("/api/admin/returns"),
    approveReturn: (returnId: number) =>
        apiRequest<ResourceResponse<OrderReturn>>(`/api/admin/returns/${returnId}/approve`, {
            method: "POST",
        }),
    rejectReturn: (returnId: number, reason: string) =>
        apiRequest<ResourceResponse<OrderReturn>>(`/api/admin/returns/${returnId}/reject`, {
            method: "POST",
            body: { reason },
        }),
    receiveReturn: (returnId: number) =>
        apiRequest<ResourceResponse<OrderReturn>>(`/api/admin/returns/${returnId}/receive`, {
            method: "POST",
        }),
    returnableItems: (id: number) =>
        apiRequest<ResourceResponse<ReturnableItem[]>>(
            `/api/admin/sales-orders/${id}/returnable-items`,
        ),
    completeRefund: (
        id: number,
        body: {
            operation_key: string;
            amount: string;
            refund_method: "cash" | "bank_transfer" | "other_manual" | "dealer_wallet";
            reason: "return" | "order_cancel" | "price_adjustment" | "service_recovery" | "other";
            return_id?: number;
            external_reference?: string;
            note?: string;
        },
    ) =>
        apiRequest<ResourceResponse<{ refund: OrderRefund; summary: OrderPayments["summary"] }>>(
            `/api/admin/sales-orders/${id}/refunds`,
            { method: "POST", body },
        ),
    completeReturn: (
        id: number,
        body: {
            operation_key: string;
            reason: string;
            note?: string;
            processing_mode?: "immediate" | "pending_inspection";
            items: {
                item_id: number;
                quantity: string;
                restock_quantity: string;
                non_restock_reason_code?: string;
                non_restock_note?: string;
            }[];
        },
    ) =>
        apiRequest<ResourceResponse<OrderReturn>>(`/api/admin/sales-orders/${id}/returns`, {
            method: "POST",
            body,
        }),
    processReturn: (
        returnId: number,
        body: {
            operation_key: string;
            items: {
                return_item_id: number;
                restock_quantity: string;
                non_restock_reason_code?: string;
                non_restock_note?: string;
            }[];
        },
    ) =>
        apiRequest<ResourceResponse<OrderReturn>>(`/api/admin/returns/${returnId}/process`, {
            method: "POST",
            body,
        }),
    recordPayment: (
        id: number,
        body: {
            operation_key: string;
            amount: string;
            payment_method: "cash" | "bank_transfer" | "other_manual";
            external_reference?: string;
            note?: string;
        },
    ) =>
        apiRequest<
            ResourceResponse<{ payment: RecordedPayment; summary: OrderPayments["summary"] }>
        >(`/api/admin/sales-orders/${id}/payments`, {
            method: "POST",
            body,
        }),
    warehouse: (id: number, operationKey: string, warehouse_id: number) =>
        apiRequest<ResourceResponse<SalesOrder>>(`/api/admin/sales-orders/${id}/warehouse`, {
            method: "POST",
            body: { operation_key: operationKey, warehouse_id },
        }),
};
