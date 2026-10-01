import { apiRequest } from "./api";
import type { ResourceResponse } from "@/types";
import type { RawPage } from "@/types/product";
import type { InventoryBalance, Reconciliation, StockMovement, Warehouse } from "@/types/inventory";

export type InventoryFilters = {
    warehouse_id?: number | undefined;
    product_variant_id?: number | undefined;
    product_id?: number | undefined;
    search?: string | undefined;
    status?: string | undefined;
    low_stock?: boolean | undefined;
    page?: number | undefined;
    per_page?: number | undefined;
};
export type MovementFilters = {
    warehouse_id?: number | undefined;
    product_variant_id?: number | undefined;
    movement_type?: string | undefined;
    from?: string | undefined;
    to?: string | undefined;
    reference?: string | undefined;
    page?: number | undefined;
    per_page?: number | undefined;
};
export type InventoryOperation = {
    warehouse_id: number;
    product_variant_id: number;
    quantity: string;
    operation_key: string;
    reason_code?: string | undefined;
    reason_detail?: string | undefined;
    reference_type?: "MANUAL" | "STOCK_MOVEMENT" | undefined;
    reference_id?: string | undefined;
};

export const inventoryKeys = {
    warehouses: (filters: object) => ["warehouses", filters] as const,
    balances: (filters: InventoryFilters) => ["inventory-balances", filters] as const,
    movements: (filters: MovementFilters) => ["stock-movements", filters] as const,
    reconciliation: (warehouseId?: number) => ["inventory-reconciliation", warehouseId] as const,
};

export const inventoryApi = {
    warehouses: (
        filters: { search?: string; status?: string; page?: number; per_page?: number } = {},
    ) => apiRequest<RawPage<Warehouse>>("/api/admin/warehouses", { query: filters }),
    createWarehouse: (body: Partial<Warehouse>) =>
        apiRequest<ResourceResponse<Warehouse>>("/api/admin/warehouses", { method: "POST", body }),
    updateWarehouse: (id: number, body: Partial<Warehouse>) =>
        apiRequest<ResourceResponse<Warehouse>>(`/api/admin/warehouses/${id}`, {
            method: "PATCH",
            body,
        }),
    balances: (filters: InventoryFilters = {}) =>
        apiRequest<RawPage<InventoryBalance>>("/api/admin/inventory", { query: filters }),
    movements: (filters: MovementFilters = {}) =>
        apiRequest<RawPage<StockMovement>>("/api/admin/stock-movements", { query: filters }),
    reconciliation: (warehouseId?: number) =>
        apiRequest<ResourceResponse<Reconciliation>>("/api/admin/inventory/reconciliation", {
            query: { warehouse_id: warehouseId },
        }),
    opening: (body: InventoryOperation) =>
        apiRequest<ResourceResponse<StockMovement>>("/api/admin/inventory/opening-stock", {
            method: "POST",
            body,
        }),
    receipt: (body: InventoryOperation) =>
        apiRequest<ResourceResponse<StockMovement>>("/api/admin/inventory/receipts", {
            method: "POST",
            body,
        }),
    adjustment: (body: InventoryOperation) =>
        apiRequest<ResourceResponse<StockMovement>>("/api/admin/inventory/adjustments", {
            method: "POST",
            body,
        }),
};
