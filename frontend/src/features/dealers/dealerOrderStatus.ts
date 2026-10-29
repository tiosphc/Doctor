import { salesOrderStatusLabel } from "@/components/common/salesOrderStatus";

export function dealerOrderMainStatuses(order: {
    order_status: string;
    payment_status: string;
    fulfillment_status: string;
}): { key: string; kind: "order" | "payment" | "fulfillment"; label: string; positive: boolean }[] {
    return (["order", "payment", "fulfillment"] as const).map((kind) => {
        const key =
            kind === "order"
                ? order.order_status
                : kind === "payment"
                  ? order.payment_status
                  : order.fulfillment_status;
        return {
            key,
            kind,
            label: salesOrderStatusLabel(kind, key),
            positive: ["confirmed", "completed", "paid", "reserved", "fulfilled"].includes(key),
        };
    });
}
