export type RetailCartLine = {
    id: number;
    product_variant_id: number;
    sku: string;
    product_id: number;
    product_name: string;
    product_slug: string;
    variant_name: string;
    unit_name: string;
    unit_symbol: string | null;
    unit_precision: number;
    image_url: string | null;
    quantity: string;
    retail_price: {
        unit_price: string;
        currency: string;
        pricing_context: "retail";
    } | null;
    line_total: string | null;
    promotion_discount_amount?: string;
    discounted_line_total?: string | null;
    discounted_unit_price?: string | null;
    available_quantity: string | null;
    errors: string[];
};

export type RetailCart = {
    gift_item: {
        is_gift: true;
        product_variant_id: number;
        product_name: string;
        variant_name: string;
        sku: string;
        quantity: string;
        unit_price: string;
        line_total: string;
    } | null;
    id: number;
    items: RetailCartLine[];
    item_count: number;
    warehouse: { id: number; code: string; name: string } | null;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    shipping_total: string;
    grand_total: string;
    voucher_code: string | null;
    voucher_percent: string | null;
    voucher_error: string | null;
    gift_unavailable_reason?: string | null;
    voucher: {
        code: string;
        name: string;
        discount_amount: string;
        discount_type: string;
        discount_value: string;
    } | null;
    promotion: {
        code: string;
        name: string;
        discount_type: string;
        discount_value: string;
        discount_amount: string;
        qualified?: boolean;
        remaining_buy_quantity?: string;
        gift_quantity?: string;
        gift_product_name?: string;
        gift_variant_name?: string;
    } | null;
    gift_promotion?: RetailCart["promotion"];
    promotions?: NonNullable<RetailCart["promotion"]>[];
    currency: "VND";
    can_checkout: boolean;
    review_fingerprint: string;
    recipient_defaults?: {
        recipient_name: string;
        recipient_phone: string | null;
        recipient_email: string | null;
    };
};

export type RecipientForm = {
    recipient_name: string;
    recipient_phone: string;
    recipient_email: string;
    shipping_address_line1: string;
    shipping_address_line2: string;
    shipping_city: string;
    shipping_district: string;
    shipping_province: string;
    shipping_country: string;
    shipping_postal_code: string;
    delivery_note: string;
    payment_method: "cod" | "bank_transfer";
};

export type RetailOrderItem = {
    is_gift?: boolean;
    id: number;
    product_name: string;
    product_code: string;
    product_id: number | null;
    variant_id: number;
    image_url: string | null;
    sku: string;
    variant_name: string;
    unit_code: string;
    unit_name: string;
    quantity: string;
    unit_price: string;
    base_amount: string;
    discount_amount: string;
    line_total: string;
};

export type RetailOrder = {
    id: number;
    order_code: string;
    sales_channel: "retail";
    order_source: "admin" | "cart";
    currency: "VND";
    order_status:
        | "pending"
        | "confirmed"
        | "preparing"
        | "shipping"
        | "delivered"
        | "processing"
        | "completed"
        | "cancelled";
    payment_status: string;
    paid_amount: string;
    refunded_amount: string;
    net_settled_amount: string;
    refund_status: string;
    refunds: {
        refund_code: string;
        amount: string;
        method: string;
        status: string;
        completed_at: string;
    }[];
    returns?: {
        return_code: string;
        completed_at: string;
        items: { quantity: string; restock_quantity: string; non_restock_quantity: string }[];
    }[];
    outstanding_amount: string;
    payment_method: "cod" | "bank_transfer";
    voucher_code: string | null;
    voucher: { code: string; discount_amount: string } | null;
    promotion: {
        code: string;
        name: string;
        discount_type: string;
        discount_value: string;
        discount_amount: string;
        gift?: Record<string, unknown> | null;
    } | null;
    promotions?: {
        code: string;
        name: string;
        discount_type: string;
        discount_value: string;
        discount_amount: string;
    }[];
    gift_promotion?: { code: string; name: string; gift: Record<string, unknown> } | null;
    fulfillment_status: string;
    recipient_name: string;
    recipient_phone: string;
    recipient_email: string | null;
    shipping_address_line1: string;
    shipping_address_line2: string | null;
    shipping_city: string;
    shipping_district: string;
    shipping_province: string;
    shipping_country: string;
    shipping_postal_code: string | null;
    delivery_note: string | null;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    shipping_total: string;
    grand_total: string;
    item_count: number;
    items: RetailOrderItem[];
    history?: {
        event_type: string;
        from_status: string | null;
        to_status: string | null;
        actor_name: string | null;
        note: string | null;
        created_at: string;
    }[];
    created_at: string;
    confirmed_at: string | null;
    cancelled_at: string | null;
    completed_at: string | null;
};
