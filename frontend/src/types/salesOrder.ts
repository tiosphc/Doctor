export type OrderStatus = "draft" | "confirmed" | "processing" | "completed" | "cancelled";
export type FulfillmentStatus = "unfulfilled" | "reserved" | "partially_fulfilled" | "fulfilled";

export type Reservation = {
    id: number;
    sales_order_item_id: number;
    original_quantity: string;
    consumed_quantity: string;
    released_quantity: string;
    status: string;
};

export type SalesOrderItem = {
    id: number;
    product_variant_id: number;
    product_code_snapshot: string;
    product_name_snapshot: string;
    sku_snapshot: string;
    variant_name_snapshot: string;
    unit_code_snapshot: string;
    unit_name_snapshot: string;
    quantity: string;
    unit_price_snapshot: string;
    line_total: string;
    pricing_context_snapshot: "retail";
    reservation: Reservation | null;
};

export type SalesOrder = {
    id: number;
    order_code: string;
    sales_channel: "retail";
    order_source: "admin";
    buyer_user_id: number;
    buyer: { id: number; name: string; email: string };
    warehouse_id: number;
    warehouse: { id: number; code: string; name: string; status?: string };
    currency: "VND";
    recipient_name: string;
    recipient_phone: string;
    recipient_email: string | null;
    shipping_address_line1: string;
    shipping_address_line2: string | null;
    shipping_city: string;
    shipping_province: string;
    shipping_country: string;
    shipping_postal_code: string | null;
    delivery_note: string | null;
    order_status: OrderStatus;
    payment_status: string;
    fulfillment_status: FulfillmentStatus;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    shipping_total: string;
    grand_total: string;
    created_at: string;
    confirmed_at: string | null;
    cancelled_at: string | null;
    completed_at: string | null;
    cancellation_reason: string | null;
    items: SalesOrderItem[];
    items_count?: number;
    reservations: Reservation[];
};

export type SalesOrderDraftInput = {
    operation_key: string;
    sales_channel: "retail";
    buyer_user_id: number;
    warehouse_id: number;
    currency: "VND";
    recipient_name: string;
    recipient_phone: string;
    recipient_email: string | null;
    shipping_address_line1: string;
    shipping_address_line2: string | null;
    shipping_city: string;
    shipping_province: string;
    shipping_country: string;
    shipping_postal_code: string | null;
    delivery_note: string | null;
    items: { sku: string; quantity: string }[];
};
