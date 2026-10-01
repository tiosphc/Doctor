import { apiRequest } from "./api";
import type { GiftPromotionSummary } from "@/types/product";

type PromotionProduct = {
    buy_product_id: number;
    buy_product_name: string;
    buy_product_slug: string;
    buy_available: boolean;
    ends_at?: string | null;
    dealer_tiers?: string[];
};

export type GiftPromotionListing = PromotionProduct &
    (
        | (GiftPromotionSummary & { discount_type: "buy_a_get_b" })
        | {
              code: string;
              name: string;
              discount_type: "percentage" | "fixed_amount";
              discount_value: string;
          }
    );

export const giftPromotionApi = {
    retail: () => apiRequest<{ data: GiftPromotionListing[] }>("/api/gift-promotions"),
    dealer: (accountId: number) =>
        apiRequest<{ data: GiftPromotionListing[] }>(
            `/api/dealer/accounts/${accountId}/gift-promotions`,
        ),
};
