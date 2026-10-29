import { createFileRoute, Outlet } from "@tanstack/react-router";

export const Route = createFileRoute("/admin/sales-promotions")({
    head: () => ({ meta: [{ title: "Ưu đãi bán hàng | Junie" }] }),
    component: () => <Outlet />,
});
