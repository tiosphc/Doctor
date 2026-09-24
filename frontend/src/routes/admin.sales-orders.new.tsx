import { createFileRoute } from "@tanstack/react-router";
import { SalesOrderCreatePage } from "@/pages/admin/SalesOrderPages";

export const Route = createFileRoute("/admin/sales-orders/new")({
    component: SalesOrderCreatePage,
});
