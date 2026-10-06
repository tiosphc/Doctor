export type Warehouse = {
    id: number;
    code: string;
    name: string;
    address_line1: string | null;
    address_line2: string | null;
    city: string | null;
    province: string | null;
    country: string | null;
    postal_code: string | null;
    timezone: string | null;
    type: string | null;
    status: "active" | "inactive";
    is_default_sales: boolean;
    is_default_clinic: boolean;
};

export type InventoryBalance = {
    id: number;
    warehouse_id: number;
    product_variant_id: number;
    warehouse: Pick<Warehouse, "id" | "code" | "name" | "status">;
    product: { id: number; product_code: string; name: string; status: string };
    variant: {
        id: number;
        sku: string;
        variant_name: string;
        status: string;
        track_inventory: boolean;
    };
    unit: { id: number; name: string; symbol: string; decimal_precision: number };
    on_hand_quantity: string;
    reserved_quantity: string;
    available_quantity: string;
    low_stock: boolean;
    last_movement_at: string | null;
};

export type InventoryProductSummary = {
    id: number;
    product_code: string;
    name: string;
    image_url: string | null;
    variant_count: number;
    on_hand_quantity: string;
    reserved_quantity: string;
    available_quantity: string;
    low_stock_count: number;
    last_movement_at: string | null;
    balances: InventoryBalance[];
};

export type StockMovement = {
    id: number;
    warehouse_id: number;
    product_variant_id: number;
    movement_type:
        | "OPENING_BALANCE"
        | "GOODS_RECEIPT"
        | "ADJUSTMENT_IN"
        | "ADJUSTMENT_OUT"
        | "PURCHASE_RETURN";
    quantity: string;
    before_on_hand_quantity: string;
    after_on_hand_quantity: string;
    reference_type: string | null;
    reference_id: string | null;
    operation_key: string;
    reason_code: string | null;
    reason_detail: string | null;
    actor_user_id: number | null;
    source: string;
    occurred_at: string;
    warehouse: Pick<Warehouse, "id" | "code" | "name">;
    variant: {
        id: number;
        sku: string;
        variant_name: string;
        product?: { id: number; product_code: string; name: string };
    };
    actor: { id: number; name: string } | null;
};

export type ReconciliationRow = {
    warehouse_id: number;
    warehouse_code: string;
    product_variant_id: number;
    sku: string;
    actual_on_hand: string | null;
    expected_on_hand: string;
    difference: string | null;
    movement_count: number;
    status: "matched" | "mismatched" | "missing_balance";
};

export type Reconciliation = {
    balances_checked: number;
    movements_checked: number;
    matched: number;
    mismatched: number;
    missing_balances: number;
    orphan_movements: number;
    rows: ReconciliationRow[];
};
