import { createFileRoute, Outlet } from "@tanstack/react-router";

export const Route = createFileRoute("/admin/sales-vouchers")({
    head: () => ({ meta: [{ title: "Voucher bán lẻ | Junie" }] }),
    component: () => <Outlet />,
});
