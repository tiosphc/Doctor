import { createFileRoute } from "@tanstack/react-router";
import { AdminVouchersPage } from "@/pages/ReviewVoucherPages";

export const Route = createFileRoute("/admin/vouchers")({
    head: () => ({ meta: [{ title: "Voucher đánh giá | Junie" }] }),
    component: AdminVouchersPage,
});
