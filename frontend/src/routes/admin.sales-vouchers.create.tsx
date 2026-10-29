import { createFileRoute } from "@tanstack/react-router";
import { SalesVoucherPage } from "@/pages/admin/SalesVoucherPage";

export const Route = createFileRoute("/admin/sales-vouchers/create")({
    component: () => <SalesVoucherPage mode="create" />,
});
