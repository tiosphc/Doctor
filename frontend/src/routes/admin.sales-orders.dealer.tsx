import { createFileRoute } from "@tanstack/react-router";
import { SalesOrderListPage } from "@/pages/admin/SalesOrderPages";

export const Route = createFileRoute("/admin/sales-orders/dealer")({
    component: () => <SalesOrderListPage channel="dealer" />,
});
