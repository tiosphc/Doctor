import { apiRequest } from "@/services/api";
import type { ResourceResponse } from "@/types";

export type ReturnChannel = { kind: "retail" } | { kind: "dealer"; accountId: number };

export type ReturnableLine = {
    item_id: number;
    product_name: string;
    sku: string;
    fulfilled_quantity: string;
    returned_quantity: string;
    pending_quantity: string;
    returnable_quantity: string;
    unit_code: string;
    decimal_precision: number;
};

export type ReturnEligibility = {
    return_eligible: boolean;
    return_deadline: string | null;
    return_days_remaining: number | null;
    return_ineligible_reason: string | null;
    return_window_days: number;
    items: ReturnableLine[];
};

export type CustomerReturn = {
    id: number;
    return_code: string;
    status: "requested" | "approved" | "rejected" | "pending" | "completed";
    reason_code: string | null;
    note: string | null;
    rejection_reason: string | null;
    requested_at: string;
    approved_at: string | null;
    received_at: string | null;
    completed_at: string | null;
    rejected_at: string | null;
    refunded_amount: number;
    refunded_at: string | null;
    items: { id: number; sku: string; product_name: string; quantity: string }[];
};

function base(channel: ReturnChannel, orderId: number): string {
    return channel.kind === "retail"
        ? `/api/retail/orders/${orderId}`
        : `/api/dealer/accounts/${channel.accountId}/orders/${orderId}`;
}

export const customerReturnApi = {
    eligibility: (channel: ReturnChannel, orderId: number) =>
        apiRequest<ResourceResponse<ReturnEligibility>>(
            `${base(channel, orderId)}/return-eligibility`,
        ),
    list: (channel: ReturnChannel, orderId: number) =>
        apiRequest<ResourceResponse<CustomerReturn[]>>(`${base(channel, orderId)}/returns`),
    request: (
        channel: ReturnChannel,
        orderId: number,
        body: {
            operation_key: string;
            reason_code: string;
            note?: string;
            items: { item_id: number; quantity: string }[];
        },
    ) =>
        apiRequest<ResourceResponse<CustomerReturn>>(`${base(channel, orderId)}/returns`, {
            method: "POST",
            body,
        }),
};
