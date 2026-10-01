import { ApiError, apiRequest, errorMessage } from "./api";
import type { PaginatedResponse, ResourceResponse } from "@/types";
import type { RecipientForm, RetailCart, RetailOrder } from "@/types/retailCommerce";

export const retailKeys = {
    cart: (userId?: number) => ["retail-cart", userId] as const,
    review: (userId?: number) => ["retail-checkout-review", userId] as const,
    orders: (userId: number | undefined, page: number) => ["retail-orders", userId, page] as const,
    order: (userId: number | undefined, id: number) => ["retail-order", userId, id] as const,
};

export const retailCommerceApi = {
    cart: () => apiRequest<ResourceResponse<RetailCart>>("/api/retail/cart"),
    add: (product_variant_id: number, quantity: string) =>
        apiRequest<ResourceResponse<RetailCart>>("/api/retail/cart/items", {
            method: "POST",
            body: { product_variant_id, quantity },
        }),
    update: (itemId: number, quantity: string) =>
        apiRequest<ResourceResponse<RetailCart>>(`/api/retail/cart/items/${itemId}`, {
            method: "PATCH",
            body: { quantity },
        }),
    remove: (itemId: number) =>
        apiRequest<ResourceResponse<RetailCart>>(`/api/retail/cart/items/${itemId}`, {
            method: "DELETE",
        }),
    clear: () => apiRequest<ResourceResponse<RetailCart>>("/api/retail/cart", { method: "DELETE" }),
    voucher: (code: string | null) =>
        apiRequest<ResourceResponse<RetailCart>>("/api/retail/cart/voucher", {
            method: "PUT",
            body: { code },
        }),
    review: () => apiRequest<ResourceResponse<RetailCart>>("/api/retail/checkout/review"),
    checkout: (
        body: RecipientForm & {
            checkout_operation_key: string;
            checkout_review_fingerprint: string;
        },
    ) =>
        apiRequest<ResourceResponse<RetailOrder>>("/api/retail/checkout", { method: "POST", body }),
    orders: (page: number) =>
        apiRequest<PaginatedResponse<RetailOrder>>("/api/retail/orders", { query: { page } }),
    order: (id: number) => apiRequest<ResourceResponse<RetailOrder>>(`/api/retail/orders/${id}`),
};

const retailErrors: Record<string, string> = {
    CART_EMPTY: "Giỏ hàng của bạn đang trống.",
    CHECKOUT_WAREHOUSE_NOT_CONFIGURED:
        "Kho bán hàng mặc định chưa được cấu hình. Vui lòng liên hệ Junie.",
    CHECKOUT_WAREHOUSE_AMBIGUOUS:
        "Cấu hình kho bán hàng cần được kiểm tra. Vui lòng liên hệ Junie.",
    CHECKOUT_CHANGED: "Giỏ hàng, giá hoặc kho đã thay đổi. Vui lòng xem lại đơn hàng.",
    CHECKOUT_ITEM_INVALID:
        "Một sản phẩm không còn đủ điều kiện đặt hàng. Vui lòng kiểm tra giỏ hàng.",
    RETAIL_SKU_NOT_ORDERABLE: "Quy cách này chưa hỗ trợ đặt hàng trực tuyến.",
    PRICE_NOT_FOUND: "Sản phẩm hiện chưa có giá Retail.",
    PRICE_AMBIGUOUS: "Giá Retail cần được kiểm tra lại. Vui lòng thử sau.",
};

export function retailErrorMessage(reason: unknown): string {
    if (reason instanceof ApiError && reason.code) {
        const message = retailErrors[reason.code];
        if (message) return message;
    }
    return errorMessage(reason);
}
