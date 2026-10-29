export type StatusKind = "order" | "payment" | "fulfillment" | "refund" | "return";

export const statusStyles: Record<
    StatusKind,
    Record<string, { label: string; className: string }>
> = {
    order: {
        draft: { label: "Bản nháp", className: "border-slate-200 bg-slate-50 text-slate-700" },
        pending: {
            label: "Chờ xác nhận",
            className: "border-amber-200 bg-amber-50 text-amber-800",
        },
        confirmed: {
            label: "Đã xác nhận",
            className: "border-indigo-200 bg-indigo-50 text-indigo-700",
        },
        preparing: {
            label: "Đang chuẩn bị hàng",
            className: "border-sky-200 bg-sky-50 text-sky-700",
        },
        shipping: { label: "Đang giao", className: "border-sky-200 bg-sky-50 text-sky-700" },
        processing: { label: "Đang xuất hàng", className: "border-sky-200 bg-sky-50 text-sky-700" },
        delivered: { label: "Đã giao", className: "border-cyan-200 bg-cyan-50 text-cyan-800" },
        completed: {
            label: "Hoàn thành",
            className: "border-emerald-200 bg-emerald-50 text-emerald-700",
        },
        cancelled: { label: "Đã hủy", className: "border-rose-200 bg-rose-50 text-rose-700" },
    },
    payment: {
        unpaid: {
            label: "Chưa thanh toán",
            className: "border-orange-200 bg-orange-50 text-orange-800",
        },
        pending: {
            label: "Chờ thanh toán",
            className: "border-orange-200 bg-orange-50 text-orange-800",
        },
        partially_paid: {
            label: "Thanh toán một phần",
            className: "border-orange-200 bg-orange-50 text-orange-800",
        },
        paid: { label: "Đã thanh toán", className: "border-teal-200 bg-teal-50 text-teal-800" },
        failed: {
            label: "Thanh toán thất bại",
            className: "border-red-200 bg-red-50 text-red-700",
        },
        partially_refunded: {
            label: "Hoàn tiền một phần",
            className: "border-purple-200 bg-purple-50 text-purple-700",
        },
        refunded: {
            label: "Đã hoàn tiền",
            className: "border-purple-200 bg-purple-50 text-purple-700",
        },
    },
    fulfillment: {
        unfulfilled: {
            label: "Chưa giữ hàng",
            className: "border-slate-200 bg-slate-50 text-slate-700",
        },
        reserved: {
            label: "Đã giữ hàng",
            className: "border-violet-200 bg-violet-50 text-violet-700",
        },
        partially_fulfilled: {
            label: "Đã xuất một phần",
            className: "border-amber-200 bg-amber-50 text-amber-800",
        },
        fulfilled: { label: "Đã xuất đủ", className: "border-cyan-200 bg-cyan-50 text-cyan-800" },
    },
    refund: {
        none: { label: "Chưa hoàn", className: "border-slate-200 bg-slate-50 text-slate-700" },
        partially_refunded: {
            label: "Đã hoàn một phần",
            className: "border-purple-200 bg-purple-50 text-purple-700",
        },
        fully_refunded: {
            label: "Đã hoàn đủ",
            className: "border-purple-200 bg-purple-50 text-purple-700",
        },
    },
    return: {
        requested: {
            label: "Đã yêu cầu",
            className: "border-amber-200 bg-amber-50 text-amber-800",
        },
        approved: {
            label: "Đã duyệt",
            className: "border-indigo-200 bg-indigo-50 text-indigo-700",
        },
        rejected: { label: "Từ chối", className: "border-rose-200 bg-rose-50 text-rose-700" },
        pending: { label: "Chờ xử lý", className: "border-amber-200 bg-amber-50 text-amber-800" },
        completed: {
            label: "Hoàn thành",
            className: "border-emerald-200 bg-emerald-50 text-emerald-700",
        },
    },
};

export function salesOrderStatusLabel(kind: StatusKind, status: string): string {
    return statusStyles[kind][status]?.label ?? status;
}

export function salesOrderTimelineLabel(status: string): string {
    return (
        statusStyles.order[status]?.label ??
        statusStyles.fulfillment[status]?.label ??
        statusStyles.payment[status]?.label ??
        status
    );
}

export function salesOrderSourceLabel(source: string): string {
    return (
        {
            quick_order: "Đặt hàng nhanh",
            dealer_excel: "Excel",
            cart: "Giỏ hàng Retail",
            admin: "Admin",
        }[source] ?? source
    );
}
