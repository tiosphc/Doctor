export type DealerAccountStatus = "active" | "suspended" | "inactive";

export type DealerTier = {
    id: number;
    code: string;
    name: string;
    status: "active" | "inactive";
    sort_order: number;
    description: string | null;
    is_default_initial: boolean;
    revenue_threshold: string | null;
};

export type DealerAutoTierProgress = {
    dealer_account_id: number;
    enabled: boolean;
    policy_version: number;
    currency: "VND";
    settled_amount: string;
    refunded_amount: string;
    returned_amount: string;
    net_revenue: string;
    revenue_period_start: string;
    revenue_period_end: string;
    evaluation_period: string;
    next_evaluation_at: string;
    current_tier: DealerTierIdentity | null;
    effective_tier: (DealerTierIdentity & { status: string }) | null;
    active_override: { id: number; starts_at: string; ends_at: string | null } | null;
    history_status: "unassigned" | "missing" | "consistent" | "mismatch";
    target_tier: DealerTierIdentity | null;
    next_tier: DealerTierIdentity | null;
    next_threshold: string | null;
    remaining_to_next: string | null;
    reason:
        | "disabled"
        | "rules_invalid"
        | "account_inactive"
        | "tier_unassigned"
        | "current_tier_inactive"
        | "current"
        | "change_required"
        | "override_active"
        | "not_month_end"
        | "already_evaluated";
    changed: boolean;
    would_change: boolean;
    direction: "upgrade" | "downgrade" | "lateral" | null;
};

export type DealerTierIdentity = Pick<DealerTier, "id" | "code" | "name">;

export type DealerTierResolution = {
    base_tier: (DealerTierIdentity & { status: string }) | null;
    effective_tier: (DealerTierIdentity & { status: string }) | null;
    source: "unassigned" | "current" | "manual_override";
    effective_at: string | null;
    expires_at: string | null;
    override: { id: number; starts_at: string; ends_at: string | null } | null;
};

export type DealerTierHistory = {
    id: number;
    previous_tier: DealerTierIdentity | null;
    new_tier: DealerTierIdentity;
    source: string;
    reason: string | null;
    net_revenue_snapshot: string | null;
    policy_version: number | null;
    evaluation_period: string | null;
    revenue_period_start: string | null;
    revenue_period_end: string | null;
    evaluated_at: string | null;
    effective_at: string;
    expires_at: string | null;
    actor: { id: number; name: string } | null;
};

export type DealerTierOverride = {
    id: number;
    tier: DealerTierIdentity;
    starts_at: string;
    ends_at: string | null;
    reason: string;
    status: "active" | "cancelled";
    created_by: { id: number; name: string } | null;
    cancelled_at: string | null;
};

export type DealerMembership = {
    id: number;
    user_id: number;
    user_name: string | null;
    membership_role: string;
    status: string;
};

export type DealerAccount = {
    id: number;
    code: string;
    legal_name: string;
    trading_name: string | null;
    contact_name: string;
    email: string;
    phone: string;
    tax_code: string | null;
    billing_address_line1: string;
    billing_address_line2: string | null;
    city: string;
    province: string;
    country: string;
    postal_code: string | null;
    status: DealerAccountStatus;
    source_application_id: number;
    created_at: string;
    membership_role?: string;
    owner?: { id: number; name: string; email: string } | null;
    tier?: DealerTierIdentity | null;
    wallet_balance?: string;
    memberships?: DealerMembership[];
};

export type DealerAccountInput = Partial<
    Pick<
        DealerAccount,
        | "legal_name"
        | "trading_name"
        | "contact_name"
        | "email"
        | "phone"
        | "tax_code"
        | "billing_address_line1"
        | "billing_address_line2"
        | "city"
        | "province"
        | "country"
        | "postal_code"
    >
>;

export type DealerPrice = {
    unit_price: string;
    discounted_unit_price?: string | null;
    base_unit_price: string;
    currency: "VND";
    minimum_quantity: string;
    price_fingerprint: string;
};

export type DealerProduct = {
    dealer_discount_promotion?: {
        id: number;
        code: string;
        name: string;
        discount_type: "percentage" | "fixed_amount";
        discount_value: string;
        minimum_order_amount: string;
    } | null;
    active_promotions?: {
        name: string;
        discount_type: "percentage" | "fixed_amount";
        discount_value: string;
        minimum_order_amount: string;
    }[];
    gift_promotions?: import("@/types/product").GiftPromotionSummary[];
    id: number;
    product_code: string;
    name: string;
    slug: string;
    description: string | null;
    category: { id: number; code: string; name: string } | null;
    brand: { id: number; code: string; name: string } | null;
    images: {
        id: number;
        url: string;
        alt_text: string | null;
        product_variant_id: number | null;
        is_primary: boolean;
    }[];
    variants: {
        id: number;
        sku: string;
        variant_name: string;
        specifications?: Record<string, unknown> | null;
        unit: string | null;
        unit_symbol: string | null;
        unit_precision: number | null;
        dealer_price: DealerPrice;
    }[];
};

export type DealerCatalogResponse = {
    data: DealerProduct[];
    dealer_account: Pick<DealerAccount, "id" | "code" | "legal_name">;
    effective_tier: DealerTierResolution["effective_tier"];
    warehouse: DealerWarehouse | null;
    meta: { current_page: number; last_page: number; per_page: number; total: number };
};

export type DealerProductResponse = {
    data: DealerProduct;
    dealer_account: DealerCatalogResponse["dealer_account"];
    effective_tier: DealerTierResolution["effective_tier"];
    warehouse: DealerWarehouse | null;
};

export type DealerWarehouse = {
    id: number;
    code: string;
    name: string;
    is_default_sales?: boolean;
};

export type DealerQuote = {
    dealer_account: DealerCatalogResponse["dealer_account"];
    base_tier: DealerTierResolution["base_tier"];
    effective_tier: DealerTierResolution["effective_tier"];
    pricing_source: DealerTierResolution["source"];
    product: Pick<DealerProduct, "id" | "product_code" | "name" | "slug">;
    variant: {
        id: number;
        sku: string;
        variant_name: string;
        unit: string | null;
        unit_symbol: string | null;
    };
    quantity: string;
    unit_price: string;
    base_unit_price: string;
    warehouse: DealerWarehouse | null;
    line_total: string;
    discounted_unit_price?: string;
    discounted_line_total?: string;
    promotion?: {
        code: string;
        name: string;
        discount_type: "percentage" | "fixed_amount";
        discount_value: string;
        discount_amount: string;
    } | null;
    currency: string;
    minimum_quantity: string;
    meets_moq: boolean;
    price_fingerprint: string;
};

export type DealerQuickOrderLine = {
    product_variant_id: number;
    product_name: string | null;
    image_url: string | null;
    sku: string | null;
    variant_name: string | null;
    unit_name: string | null;
    unit_symbol: string | null;
    unit_precision: number | null;
    quantity: string;
    unit_price: string | null;
    base_unit_price?: string | null;
    minimum_quantity: string | null;
    line_total: string | null;
    promotion_discount_amount?: string;
    discounted_line_total?: string | null;
    discounted_unit_price?: string | null;
    available_quantity: string;
    available_for_requested_quantity: boolean;
    availability_status: "available" | "insufficient";
    errors: string[];
};

export type DealerQuickOrderReview = {
    preferred_warehouse?: DealerWarehouse;
    fallback_used?: boolean;
    default_area_used?: boolean;
    warehouse_sufficient?: boolean;
    gift_unavailable_reason?: string | null;
    gift_item?: {
        is_gift: true;
        product_variant_id: number;
        product_name: string;
        variant_name: string;
        sku: string;
        quantity: string;
        unit_price: string;
        line_total: string;
    } | null;
    dealer_account: Pick<DealerAccount, "id" | "code" | "legal_name">;
    effective_tier: DealerTierResolution["effective_tier"];
    tier_source: DealerTierResolution["source"];
    warehouse: DealerWarehouse;
    currency: "VND";
    wallet: DealerWalletSummary;
    wallet_sufficient: boolean;
    wallet_after_order: string | null;
    wallet_shortfall: string;
    recipient_defaults: DealerRecipient;
    items: DealerQuickOrderLine[];
    subtotal: string;
    discount_total: string;
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
    gift_promotion?: DealerQuickOrderReview["promotion"];
    promotions?: NonNullable<DealerQuickOrderReview["promotion"]>[];
    tax_total: string;
    shipping_total: string;
    grand_total: string;
    can_submit: boolean;
    review_fingerprint: string;
};

export type DealerWalletSummary = {
    currency: "VND";
    balance: string;
    available_balance: string;
    total_deposited?: string;
    total_spent?: string;
    total_refunded?: string;
    last_deposit_at?: string | null;
    last_transaction_at?: string | null;
};

export type DealerWalletTopUp = {
    id: number;
    top_up_code: string;
    dealer_account_id: number;
    amount: string;
    currency: "VND";
    provider: "payos";
    status: "initiating" | "pending" | "paid" | "expired" | "failed" | "cancelled";
    checkout_url: string | null;
    provider_reference: string | null;
    created_at: string;
    expires_at: string | null;
    expired_at: string | null;
    failed_at: string | null;
    cancelled_at: string | null;
    paid_at: string | null;
    completed_at: string | null;
};

export type AdminDealerWalletTopUp = Omit<DealerWalletTopUp, "checkout_url"> & {
    dealer_account: Pick<DealerAccount, "id" | "code" | "legal_name"> | null;
    provider_payment_link_id: string | null;
};

export type DealerWalletDepositRequest = {
    id: number;
    request_code: string;
    dealer_account_id: number;
    amount: string;
    status: "pending" | "approved" | "rejected";
    transaction_reference: string | null;
    note: string | null;
    rejection_reason: string | null;
    created_at: string;
    reviewed_at: string | null;
};

export type AdminDealerWalletDepositRequest = Omit<
    DealerWalletDepositRequest,
    "dealer_account_id"
> & {
    dealer_account: Pick<DealerAccount, "id" | "code" | "legal_name"> & {
        tier: DealerTierIdentity | null;
    };
    wallet_balance?: string;
    reviewed_by?: { id: number; name: string } | null;
    wallet_transaction_id?: number | null;
    wallet_transaction_code?: string | null;
};

export type DealerWalletTransaction = {
    id: number;
    wallet?: { dealer_account: Pick<DealerAccount, "id" | "code" | "legal_name"> | null };
    transaction_code: string;
    direction: "credit" | "debit";
    type: "deposit_credit" | "order_debit" | "refund_credit";
    amount: string;
    currency: string;
    balance_before: string;
    balance_after: string;
    sales_order_id: number | null;
    refund_id: number | null;
    deposit?: {
        deposit_code: string;
        method: string;
        external_reference: string | null;
        note: string | null;
        dealer_wallet_top_up_request_id: number | null;
    } | null;
    actor?: { id: number; name: string } | null;
    sales_order?: { id: number; order_code: string } | null;
    refund?: { id: number; refund_code: string; sales_order_id: number } | null;
    created_at: string;
};

export type AdminDealerWalletRow = {
    id: number;
    code: string;
    legal_name: string;
    phone: string;
    status: DealerAccountStatus;
    tier: DealerTierIdentity | null;
    balance: string;
    last_deposit_amount: string | null;
    last_transaction_at: string | null;
};

export type DealerWalletDeposit = {
    id: number;
    deposit_code: string;
    amount: string;
    method: string;
    external_reference: string | null;
    note: string | null;
    completed_at: string;
    recorder: { id: number; name: string } | null;
    transaction: { transaction_code: string } | null;
};

export type DealerRecipient = {
    shipping_address_id?: number;
    shipping_province_code?: string;
    shipping_ward_code?: string;
    shipping_ward?: string;
    shipping_district?: string | null;
    recipient_name: string;
    recipient_phone: string;
    recipient_email?: string | null;
    shipping_address_line1: string;
    shipping_address_line2?: string | null;
    shipping_city: string;
    shipping_province: string;
    shipping_country: string;
    shipping_postal_code?: string | null;
    delivery_note?: string | null;
};

export type DealerShippingFormValue = {
    recipient_name: string;
    recipient_phone: string;
    province_code: string;
    ward_code: string;
    address_line: string;
    shipping_district: string;
    save_address: boolean;
};

export const emptyDealerShippingForm: DealerShippingFormValue = {
    recipient_name: "",
    recipient_phone: "",
    province_code: "",
    ward_code: "",
    address_line: "",
    shipping_district: "",
    save_address: false,
};

export type DealerShippingAddressInput = {
    recipient_name: string;
    recipient_phone: string;
    address_line: string;
    province_code: string;
    ward_code: string;
    postal_code?: string | null;
    is_default?: boolean;
};

export type DealerShippingAddress = DealerShippingAddressInput & {
    id: number;
    dealer_account_id: number;
    district_legacy: string | null;
    is_default: boolean;
    province: { code: string; name: string };
    ward: { code: string; name: string; province_code: string };
};

export type DealerOrder = DealerRecipient & {
    id: number;
    order_code: string;
    sales_channel: "dealer";
    order_source: "quick_order" | "dealer_excel";
    external_reference: string | null;
    dealer_account: Pick<DealerAccount, "id" | "code" | "legal_name">;
    effective_tier: { code: string; name: string; source: string };
    placed_by?: { id: number; name: string } | null;
    warehouse?: { code: string; name: string } | null;
    currency: "VND";
    order_status: "pending" | "confirmed" | "processing" | "delivered" | "completed" | "cancelled";
    payment_status: string;
    payment_method: string | null;
    paid_amount: string;
    refunded_amount: string;
    refundable_amount: string;
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
    fulfillment_status: string;
    subtotal: string;
    discount_total: string;
    promotion?: {
        code: string;
        name: string;
        discount_type: string;
        discount_value: string;
        discount_amount: string;
    } | null;
    promotions?: {
        code: string;
        name: string;
        discount_type: string;
        discount_value: string;
        discount_amount: string;
    }[];
    gift_promotion?: { code: string; name: string; gift: Record<string, unknown> } | null;
    tax_total: string;
    shipping_total: string;
    grand_total: string;
    item_count?: number;
    total_quantity?: string | null;
    items?: {
        is_gift?: boolean;
        id: number;
        product_variant_id: number | null;
        product_name: string;
        product_code: string;
        sku: string;
        variant_name: string;
        unit_code: string;
        unit_name: string;
        quantity: string;
        shipped_quantity: string | null;
        unit_price: string;
        base_amount: string;
        discount_amount: string;
        minimum_quantity: string;
        line_total: string;
    }[];
    created_at: string;
    confirmed_at: string | null;
};

export type DealerImportError = { row: number | null; field: string; code: string; value?: string };

export type DealerImportGroup = {
    id: number;
    external_reference: string;
    status: "preview_ready" | "invalid" | "created" | "failed";
    preview: {
        gift_item?: DealerQuickOrderReview["gift_item"];
        promotion_code: string | null;
        recipient: DealerRecipient;
        effective_tier: { name: string; code: string } | null;
        warehouse: { id: number; code: string; name: string } | null;
        preferred_warehouse?: { id: number; code: string; name: string } | null;
        fallback_used?: boolean;
        items: DealerQuickOrderLine[];
        subtotal: string;
        discount_total: string;
        grand_total: string;
        errors: DealerImportError[];
    } | null;
    error_code: string | null;
    sales_order_id: number | null;
    order_code: string | null;
};

export type DealerImport = {
    warehouse_id: number | null;
    id: number;
    dealer_account_id: number;
    original_filename: string;
    file_hash: string;
    file_size: number;
    import_mode: "multi_order";
    status: string;
    row_count: number;
    order_count: number;
    valid_order_count: number;
    invalid_order_count: number;
    preview_fingerprint: string;
    preview_summary: {
        estimated_total: string;
        warehouse_id: number | null;
        valid_row_count: number;
        invalid_row_count: number;
        sku_count: number;
        wallet_balance?: string;
        wallet_sufficient?: boolean;
    };
    uploaded_by: string;
    created_at: string;
    confirmed_at: string | null;
    rows: {
        row: number;
        external_reference: string | null;
        sku: string;
        quantity: string;
        errors: DealerImportError[];
    }[];
    groups: DealerImportGroup[];
};
