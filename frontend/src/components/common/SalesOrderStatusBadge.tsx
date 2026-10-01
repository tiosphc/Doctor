import { statusStyles } from "./salesOrderStatus";
import type { StatusKind } from "./salesOrderStatus";

export function SalesOrderStatusBadge({
    kind,
    status,
    labeled = false,
}: {
    kind: StatusKind;
    status: string;
    labeled?: boolean;
}) {
    const entry = statusStyles[kind][status];
    const prefix =
        kind === "order"
            ? "Đơn"
            : kind === "payment"
              ? "Thanh toán"
              : kind === "fulfillment"
                ? "Xuất hàng"
                : kind === "refund"
                  ? "Hoàn tiền"
                  : "Trả hàng";
    return (
        <span
            className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold leading-5 ${entry?.className ?? "border-slate-200 bg-slate-50 text-slate-700"}`}
        >
            {labeled ? `${prefix}: ` : ""}
            {entry?.label ?? status}
        </span>
    );
}
