import { createFileRoute } from "@tanstack/react-router";
import { AdminCustomersPage } from "@/pages/admin/AdminPages";

export const Route = createFileRoute("/admin/customers/")({
    head: () => ({ meta: [{ title: "Khách hàng | Junie" }] }),
    component: AdminCustomersPage,
});
