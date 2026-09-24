import { createFileRoute } from "@tanstack/react-router";
import { SalesOrderDetailPage } from "@/pages/admin/SalesOrderPages";

export const Route = createFileRoute("/admin/sales-orders/$id")({
    component: SalesOrderDetailRoute,
});

function SalesOrderDetailRoute() {
    const { id } = Route.useParams();
    return <SalesOrderDetailPage id={Number(id)} />;
}
