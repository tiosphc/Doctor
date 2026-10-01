export type OrderStatus =
    | "draft"
    | "pending"
    | "confirmed"
    | "preparing"
    | "shipping"
    | "delivered"
    | "processing"
    | "completed"
    | "cancelled";
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
    product_id: number | null;
    image_url: string | null;
    product_code_snapshot: string;
    product_name_snapshot: string;
    sku_snapshot: string;
    variant_name_snapshot: string;
    unit_code_snapshot: string;
    unit_name_snapshot: string;
    quantity: string;
    unit_price_snapshot: string;
    minimum_quantity_snapshot: string | null;
    line_total: string;
    base_amount: string;
    discount_amount: string;
    pricing_context_snapshot: "retail" | "dealer";
    reservation: Reservation | null;
};

export type SalesOrder = {
    id: number;
    order_code: string;
    sales_channel: "retail" | "dealer";
    order_source: "admin" | "cart" | "quick_order" | "dealer_excel";
    external_reference: string | null;
    dealer_account_id: number | null;
    dealer_code_snapshot: string | null;
    dealer_name_snapshot: string | null;
    effective_tier_code_snapshot: string | null;
    effective_tier_name_snapshot: string | null;
    tier_source_snapshot: string | null;
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
    shipping_district: string | null;
    shipping_province: string;
    shipping_country: string;
    shipping_postal_code: string | null;
    delivery_note: string | null;
    order_status: OrderStatus;
    payment_status: string;
    paid_amount: string;
    refunded_amount: string;
    refundable_amount: string;
    net_settled_amount: string;
    refund_status: string;
    outstanding_amount: string;
    payment_method: "cod" | "bank_transfer" | "dealer_wallet" | "cash" | "other_manual" | null;
    voucher_code_snapshot: string | null;
    promotion_code_snapshot: string | null;
    promotion_name_snapshot: string | null;
    promotion_discount_type_snapshot: string | null;
    promotion_discount_value_snapshot: string | null;
    fulfillment_status: FulfillmentStatus;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    shipping_total: string;
    grand_total: string;
    cogs_total?: string | null;
    gross_profit?: string | null;
    created_at: string;
    confirmed_at: string | null;
    cancelled_at: string | null;
    completed_at: string | null;
    cancellation_reason: string | null;
    items: SalesOrderItem[];
    items_count?: number;
    total_quantity?: string | null;
    reservations: Reservation[];
    histories: {
        id: number;
        event_type: string;
        from_status: string | null;
        to_status: string | null;
        note: string | null;
        actor: { id: number; name: string } | null;
        created_at: string;
    }[];
};

export type RecordedPayment = {
    id: number;
    payment_code: string;
    payment_context: "retail" | "dealer";
    dealer_account_id: number | null;
    currency: string;
    amount: string;
    payment_method: "cash" | "bank_transfer" | "other_manual" | "dealer_wallet" | "cod";
    status: string;
    external_reference: string | null;
    note: string | null;
    recorded_by: { id: number; name: string } | null;
    settled_at: string | null;
    created_at: string;
};

export type OrderPayments = {
    summary: { paid_amount: string; outstanding_amount: string; payment_status: string };
    payments: RecordedPayment[];
};

export type OrderRefund = {
    id: number;
    refund_code: string;
    return_id: number | null;
    amount: string;
    refund_method: string;
    reason: string;
    status: string;
    completed_at: string;
};

export type OrderReturn = {
    id: number;
    return_code: string;
    warehouse_id: number;
    status: "requested" | "approved" | "rejected" | "pending" | "completed";
    request_source: "dealer" | "retail" | "admin";
    requested_by: { id: number; name: string } | null;
    approved_by: { id: number; name: string } | null;
    rejected_by: { id: number; name: string } | null;
    rejection_reason: string | null;
    reason_code: string | null;
    approved_at: string | null;
    rejected_at: string | null;
    received_at: string | null;
    reason: string;
    note: string | null;
    created_at: string;
    completed_at: string | null;
    received_by: { id: number; name: string } | null;
    processed_by: { id: number; name: string } | null;
    return_value: number;
    refunded_amount: number;
    items: {
        id: number;
        sales_order_item_id: number;
        product_name: string | null;
        sku: string;
        quantity: string;
        restock_quantity: string;
        non_restock_quantity: string;
        non_restock_reason_code: string | null;
        non_restock_note: string | null;
        stock_movement_id: number | null;
    }[];
};

export type ReturnableItem = {
    item_id: number;
    product_name: string;
    sku: string;
    fulfilled_quantity: string;
    returned_quantity: string;
    pending_quantity: string;
    returnable_quantity: string;
    unit_code: string;
    unit_price: string;
};

export type SalesOrderDraftInput = {
    operation_key: string;
    confirm?: boolean;
    confirm_operation_key?: string;
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
    shipping_district?: string | null;
    shipping_province: string;
    shipping_country: string;
    shipping_postal_code: string | null;
    delivery_note: string | null;
    payment_method?: "cod" | "bank_transfer" | null;
    items: { sku: string; quantity: string }[];
};
