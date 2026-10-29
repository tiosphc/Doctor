import { describe, expect, test } from "bun:test";
import { salesOrderStatusLabel, statusStyles } from "./src/components/common/salesOrderStatus";

describe("Sales order status presentation", () => {
    test("completed order, paid payment and fulfilled stock use distinct badge colors", () => {
        expect(statusStyles.order.completed.className).toContain("emerald");
        expect(statusStyles.payment.paid.className).toContain("teal");
        expect(statusStyles.fulfillment.fulfilled.className).toContain("cyan");
    });

    test("actual order lifecycle states have Vietnamese labels", () => {
        expect(salesOrderStatusLabel("order", "confirmed")).toBe("Đã xác nhận");
        expect(salesOrderStatusLabel("payment", "unpaid")).toBe("Chưa thanh toán");
        expect(salesOrderStatusLabel("fulfillment", "reserved")).toBe("Đã giữ hàng");
    });

    test("unmapped statuses remain readable", () => {
        expect(salesOrderStatusLabel("order", "unknown_from_server")).toBe("unknown_from_server");
    });
});
