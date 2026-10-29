import { describe, expect, test } from "bun:test";
import { dealerOrderMainStatuses } from "./src/features/dealers/dealerOrderStatus";

describe("Dealer quick order success statuses", () => {
    test("unpaid orders never display a paid badge", () => {
        const statuses = dealerOrderMainStatuses({
            order_status: "confirmed",
            payment_status: "unpaid",
            fulfillment_status: "reserved",
        });

        expect(statuses.map((entry) => entry.label)).toEqual([
            "Đã xác nhận",
            "Chưa thanh toán",
            "Đã giữ hàng",
        ]);
        expect(statuses[1]?.positive).toBe(false);
    });

    test("wallet-paid orders display the actual paid and reservation states", () => {
        const statuses = dealerOrderMainStatuses({
            order_status: "confirmed",
            payment_status: "paid",
            fulfillment_status: "reserved",
        });

        expect(statuses.map((entry) => entry.label)).toEqual([
            "Đã xác nhận",
            "Đã thanh toán",
            "Đã giữ hàng",
        ]);
    });

    test("a changed fulfillment state is not shown as reserved", () => {
        const statuses = dealerOrderMainStatuses({
            order_status: "processing",
            payment_status: "partially_paid",
            fulfillment_status: "partially_fulfilled",
        });

        expect(statuses.map((entry) => entry.label)).toEqual([
            "Đang xuất hàng",
            "Thanh toán một phần",
            "Đã xuất một phần",
        ]);
    });

    test("an unreserved order does not display confirmed or reserved", () => {
        const statuses = dealerOrderMainStatuses({
            order_status: "draft",
            payment_status: "unpaid",
            fulfillment_status: "unfulfilled",
        });

        expect(statuses.map((entry) => entry.label)).toEqual([
            "Bản nháp",
            "Chưa thanh toán",
            "Chưa giữ hàng",
        ]);
    });
});
