import { apiRequest } from "./api";
import type { RawPage } from "@/types/product";
import type { ResourceResponse } from "@/types";

export type SalesPromotion = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    discount_type: "percentage" | "fixed_amount" | "buy_a_get_b";
    discount_value: string;
    max_discount_amount: string | null;
    minimum_order_amount: string;
    sales_scope: "retail" | "dealer" | "both";
    starts_at: string | null;
    ends_at: string | null;
    total_usage_limit: number | null;
    per_buyer_usage_limit: number | null;
    status: "active" | "inactive";
    redeemed_count: number;
    targets: { id: number; product_id: number | null; product_category_id: number | null }[];
    gift_rule?: SalesPromotionGiftRule | null;
    dealer_tiers?: { id: number; code: string; name: string }[];
    gift_units_granted?: string;
    gift_units_returned?: string;
    related_orders?: {
        order_code: string | null;
        sales_channel: string;
        redeemed_at: string;
        redemption_status: string;
        order_status: string | null;
        gift_sku: string | null;
        gift_quantity: string | null;
    }[];
};

export type SalesPromotionGiftRule = {
    buy_product_id: number | null;
    buy_variant_id: number | null;
    minimum_buy_quantity: string;
    gift_product_id: number | null;
    gift_variant_id: number | null;
    gift_quantity: string;
    repeat_per_multiple: boolean;
};

export type SalesPromotionInput = Omit<
    SalesPromotion,
    "id" | "redeemed_count" | "targets" | "gift_rule" | "dealer_tiers" | "gift_units_granted"
> & {
    product_ids: number[];
    category_ids: number[];
    dealer_tier_ids: number[];
    gift_rule: SalesPromotionGiftRule;
};

export const salesPromotionApi = {
    list: (filters: {
        search?: string;
        status?: string;
        sales_scope?: string;
        discount_type?: string;
        page?: number;
    }) => apiRequest<RawPage<SalesPromotion>>("/api/admin/sales-promotions", { query: filters }),
    generateCode: () => apiRequest<{ code: string }>("/api/admin/sales-promotions/generate-code"),
    detail: (id: number) =>
        apiRequest<ResourceResponse<SalesPromotion>>(`/api/admin/sales-promotions/${id}`),
    setActive: (id: number, active: boolean) =>
        apiRequest<ResourceResponse<SalesPromotion>>(
            `/api/admin/sales-promotions/${id}/${active ? "activate" : "deactivate"}`,
            { method: "POST" },
        ),
    create: (body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<SalesPromotion>>("/api/admin/sales-promotions", {
            method: "POST",
            body,
        }),
    update: (id: number, body: Record<string, unknown>) =>
        apiRequest<ResourceResponse<SalesPromotion>>(`/api/admin/sales-promotions/${id}`, {
            method: "PATCH",
            body,
        }),
};
