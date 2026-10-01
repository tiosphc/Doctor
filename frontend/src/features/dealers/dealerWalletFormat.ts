export const walletMoney = (value: string | number) =>
    `${new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 0 }).format(Number(value))} ₫`;

export const walletDate = (value: string | null) =>
    value
        ? new Date(value).toLocaleString("vi-VN", {
              year: "numeric",
              month: "2-digit",
              day: "2-digit",
              hour: "2-digit",
              minute: "2-digit",
          })
        : "—";

export const walletType = (type: string) =>
    ({
        deposit_credit: "Nạp tiền",
        order_debit: "Thanh toán đơn hàng",
        refund_credit: "Hoàn tiền",
        adjustment_credit: "Điều chỉnh tăng",
        adjustment_debit: "Điều chỉnh giảm",
    })[type] ?? "Giao dịch ví";

export const walletMethod = (method: string | undefined) =>
    ({
        bank_transfer: "Chuyển khoản",
        cash: "Tiền mặt",
        other_manual: "Khác",
    })[method ?? ""] ?? "—";
