import { createFileRoute } from "@tanstack/react-router";
import { AdminVoucherManagementPage } from "@/pages/ReviewVoucherPages";

export const Route = createFileRoute("/admin/voucher-management")({
    head: () => ({ meta: [{ title: "Quản lý voucher | Junie" }] }),
    component: AdminVoucherManagementPage,
});
