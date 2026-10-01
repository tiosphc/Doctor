import { expect, test } from "bun:test";
import { commerceCodeMessage } from "./src/services/commerceErrors";

test("stock and MOQ errors explain the affected SKU and required quantity", () => {
    expect(
        commerceCodeMessage("INSUFFICIENT_STOCK", "ABC-01", {
            available: "4.000",
            requested: "10.000",
        }),
    ).toContain("SKU ABC-01 chỉ còn 4 sản phẩm, nhưng bạn đang đặt 10");
    expect(commerceCodeMessage("DEALER_MOQ_NOT_MET", "ABC-01", { minimum: "10.000" })).toContain(
        "SKU ABC-01 yêu cầu đặt tối thiểu 10 sản phẩm",
    );
});

test("unknown business codes never leak into customer facing copy", () => {
    expect(commerceCodeMessage("NEW_INTERNAL_ERROR_CODE")).toBe(
        "Dữ liệu đơn hàng không còn hợp lệ. Vui lòng tải lại và kiểm tra.",
    );
});
