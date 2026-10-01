import { apiRequest } from "./api";
import type { ResourceResponse } from "@/types";
import type { RawPage } from "@/types/product";
import type {
    GoodsReceipt,
    PurchaseOrder,
    PurchaseOrderInput,
    PurchaseReturn,
    Supplier,
} from "@/types/procurement";

export const procurementApi = {
    suppliers: (
        filters: { search?: string; status?: string; page?: number; per_page?: number } = {},
    ) => apiRequest<RawPage<Supplier>>("/api/admin/suppliers", { query: filters }),
    createSupplier: (body: Partial<Supplier>) =>
        apiRequest<ResourceResponse<Supplier>>("/api/admin/suppliers", { method: "POST", body }),
    updateSupplier: (id: number, body: Partial<Supplier>) =>
        apiRequest<ResourceResponse<Supplier>>(`/api/admin/suppliers/${id}`, {
            method: "PATCH",
            body,
        }),
    orders: (
        filters: {
            search?: string;
            status?: string;
            supplier_id?: number;
            warehouse_id?: number;
            page?: number;
        } = {},
    ) => apiRequest<RawPage<PurchaseOrder>>("/api/admin/purchase-orders", { query: filters }),
    order: (id: number) =>
        apiRequest<ResourceResponse<PurchaseOrder>>(`/api/admin/purchase-orders/${id}`),
    createOrder: (body: PurchaseOrderInput) =>
        apiRequest<ResourceResponse<PurchaseOrder>>("/api/admin/purchase-orders", {
            method: "POST",
            body,
        }),
    updateOrder: (id: number, body: Partial<PurchaseOrderInput>) =>
        apiRequest<ResourceResponse<PurchaseOrder>>(`/api/admin/purchase-orders/${id}`, {
            method: "PATCH",
            body,
        }),
    issueOrder: (id: number) =>
        apiRequest<ResourceResponse<PurchaseOrder>>(`/api/admin/purchase-orders/${id}/issue`, {
            method: "POST",
        }),
    cancelOrder: (id: number) =>
        apiRequest<ResourceResponse<PurchaseOrder>>(`/api/admin/purchase-orders/${id}/cancel`, {
            method: "POST",
        }),
    recordPayment: (id: number, body: {
        operation_key: string;
        amount: string;
        payment_method: string;
        paid_at: string;
        external_reference?: string;
        note?: string;
    }) => apiRequest<ResourceResponse<PurchaseOrder>>(`/api/admin/purchase-orders/${id}/payments`, {
        method: "POST", body,
    }),
    receive: (
        id: number,
        body: {
            operation_key: string;
            supplier_reference?: string;
            note?: string;
            items: { purchase_order_item_id: number; quantity: string }[];
        },
    ) =>
        apiRequest<ResourceResponse<GoodsReceipt>>(
            `/api/admin/purchase-orders/${id}/goods-receipts`,
            { method: "POST", body },
        ),
    returnGoods: (
        id: number,
        body: {
            operation_key: string;
            reason: string;
            items: { goods_receipt_item_id: number; quantity: string }[];
        },
    ) =>
        apiRequest<ResourceResponse<PurchaseReturn>>(`/api/admin/purchase-orders/${id}/returns`, {
            method: "POST",
            body,
        }),
};
