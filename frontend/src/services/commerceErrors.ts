const messages: Record<string, string> = {
    VOUCHER_NOT_FOUND: "Mã voucher không tồn tại.",
    VOUCHER_INACTIVE: "Voucher này đã ngừng hoạt động.",
    VOUCHER_NOT_STARTED: "Voucher này chưa đến thời gian sử dụng.",
    VOUCHER_EXPIRED: "Voucher này đã hết hạn.",
    VOUCHER_MINIMUM_NOT_MET: "Đơn hàng chưa đạt giá trị tối thiểu để sử dụng voucher này.",
    VOUCHER_USAGE_LIMIT_REACHED: "Voucher này đã hết lượt sử dụng.",
    VOUCHER_BUYER_LIMIT_REACHED: "Bạn đã sử dụng hết số lần cho phép của voucher này.",
    VOUCHER_NOT_AVAILABLE_FOR_DEALER: "Voucher hiện không áp dụng cho đơn hàng đại lý.",
    PRICE_NOT_FOUND: "SKU hiện chưa có giá bán. Vui lòng chọn quy cách khác.",
    PRICE_AMBIGUOUS: "Giá bán của SKU cần được kiểm tra. Vui lòng liên hệ hỗ trợ.",
    OUT_OF_STOCK: "SKU đã hết hàng. Vui lòng chọn sản phẩm khác.",
    INSUFFICIENT_STOCK: "SKU không đủ hàng. Vui lòng giảm số lượng đặt.",
    IMPORT_BATCH_INSUFFICIENT_STOCK:
        "Tổng số lượng trong file vượt tồn kho khả dụng. Vui lòng giảm số lượng.",
    PRODUCT_INACTIVE: "Sản phẩm đã ngừng bán. Vui lòng xóa khỏi giỏ hàng.",
    SKU_INACTIVE: "Quy cách đã ngừng bán. Vui lòng chọn quy cách khác.",
    SKU_NOT_RETAIL_SELLABLE: "Quy cách này không bán lẻ.",
    RETAIL_SKU_NOT_ORDERABLE: "Quy cách chưa hỗ trợ đặt hàng trực tuyến.",
    DEALER_SKU_NOT_SELLABLE: "SKU này không bán cho đại lý. Vui lòng chọn SKU khác.",
    DEALER_PRICE_NOT_FOUND: "SKU chưa có giá cho hạng đại lý hiện tại. Vui lòng liên hệ hỗ trợ.",
    DEALER_MOQ_NOT_MET: "Số lượng chưa đạt mức đặt tối thiểu. Vui lòng tăng số lượng.",
    INVALID_QUANTITY: "Số lượng phải là số nguyên lớn hơn 0.",
    INVALID_QUANTITY_PRECISION: "Số lượng không đúng đơn vị của SKU. Vui lòng nhập lại.",
    GIFT_ONLY_PRODUCT_NOT_PURCHASABLE: "Sản phẩm này chỉ dùng làm quà tặng.",
    PROMOTION_GIFT_OUT_OF_STOCK: "Quà tặng đã hết hàng. Vui lòng chọn ưu đãi khác.",
    REQUIRED_FIELD: "Thông tin bắt buộc còn thiếu. Vui lòng điền trước khi nhập đơn.",
    SKU_NOT_FOUND: "Không tìm thấy SKU. Vui lòng kiểm tra lại mã sản phẩm.",
    INVALID_EMAIL: "Email không đúng định dạng. Vui lòng nhập lại.",
    INVALID_PHONE: "Số điện thoại không hợp lệ. Vui lòng nhập lại.",
    ORDER_GROUP_RECIPIENT_MISMATCH:
        "Thông tin người nhận của cùng một đơn không khớp. Vui lòng sửa các dòng liên quan.",
    ORDER_GROUP_NOTE_MISMATCH:
        "Ghi chú của cùng một đơn không khớp. Vui lòng sửa các dòng liên quan.",
    ORDER_GROUP_PROMOTION_MISMATCH:
        "Mã ưu đãi của cùng một đơn không khớp. Vui lòng sửa các dòng liên quan.",
    DEALER_WALLET_INSUFFICIENT_BALANCE: "Số dư ví không đủ. Vui lòng nạp tiền trước khi nhập đơn.",
    DEALER_ORDER_CHANGED: "Giá hoặc dữ liệu đơn đã thay đổi. Vui lòng xem lại trước khi gửi.",
    OPERATION_KEY_CONFLICT: "Thao tác đã được xác nhận với dữ liệu khác. Vui lòng tải lại.",
    EXTERNAL_ORDER_REF_ALREADY_USED:
        "Đơn này đã được tạo trước đó. Vui lòng kiểm tra lịch sử đơn hàng.",
};

export function commerceCodeMessage(
    code: string,
    sku?: string,
    context?: { available?: string | null; requested?: string | null; minimum?: string | null },
): string {
    if (code === "SKU_NOT_FOUND" && sku) {
        return `Không tìm thấy SKU ${sku}. Vui lòng kiểm tra lại mã sản phẩm.`;
    }
    if (
        (code === "INSUFFICIENT_STOCK" || code === "OUT_OF_STOCK") &&
        sku &&
        context?.available != null &&
        context?.requested != null
    ) {
        return `SKU ${sku} chỉ còn ${Number(context.available)} sản phẩm, nhưng bạn đang đặt ${Number(context.requested)}. Vui lòng giảm số lượng.`;
    }
    if (code === "DEALER_MOQ_NOT_MET" && sku && context?.minimum != null) {
        return `SKU ${sku} yêu cầu đặt tối thiểu ${Number(context.minimum)} sản phẩm. Vui lòng tăng số lượng.`;
    }

    return messages[code] ?? "Dữ liệu đơn hàng không còn hợp lệ. Vui lòng tải lại và kiểm tra.";
}
