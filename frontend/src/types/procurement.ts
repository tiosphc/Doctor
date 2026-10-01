export type Supplier = {
    id: number;
    code: string;
    name: string;
    contact_name: string | null;
    email: string | null;
    phone: string | null;
    tax_code: string | null;
    address: string | null;
    status: "active" | "inactive";
};

export type PurchaseOrderItem = {
    id: number;
    product_variant_id: number;
    sku_snapshot: string;
    name_snapshot: string;
    ordered_quantity: string;
    received_quantity: string;
    unit_price: string;
    line_total: string;
    variant?: { unit?: { symbol: string; decimal_precision: number } };
};

export type GoodsReceiptItem = {
    id: number;
    purchase_order_item_id: number;
    quantity: string;
    returned_quantity: string;
    order_item?: Pick<PurchaseOrderItem, "id" | "sku_snapshot" | "product_variant_id">;
};

export type GoodsReceipt = {
    id: number;
    code: string;
    purchase_order_id: number;
    supplier_reference: string | null;
    received_at: string;
    items: GoodsReceiptItem[];
};

export type PurchaseReturn = {
    id: number;
    code: string;
    purchase_order_id: number;
    reason: string;
    returned_at: string;
    items: { id: number; goods_receipt_item_id: number; quantity: string }[];
};

export type PurchaseOrder = {
    id: number;
    code: string;
    supplier_id: number;
    warehouse_id: number;
    status: "draft" | "ordered" | "partially_received" | "received" | "cancelled";
    currency: "VND";
    total_amount: string;
    paid_amount: string;
    payment_status: "unpaid" | "partially_paid" | "paid";
    expected_delivery_date: string | null;
    supplier_order_reference: string | null;
    note: string | null;
    created_at: string;
    supplier: Pick<Supplier, "id" | "code" | "name" | "status">;
    warehouse: { id: number; code: string; name: string; status: string };
    items: PurchaseOrderItem[];
    receipts: GoodsReceipt[];
    returns: PurchaseReturn[];
    payments: {
        id: number;
        amount: string;
        payment_method: string;
        paid_at: string;
        external_reference: string | null;
        note: string | null;
    }[];
};

export type PurchaseOrderInput = {
    supplier_id: number;
    warehouse_id: number;
    note?: string;
    expected_delivery_date?: string | null;
    supplier_order_reference?: string | null;
    items: { product_variant_id: number; quantity: string; unit_price: string }[];
};
