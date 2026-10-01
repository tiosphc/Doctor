import { createFileRoute } from "@tanstack/react-router";
import { PurchaseOrderDetailPage } from "@/pages/admin/PurchaseOrderPages";

export const Route = createFileRoute("/admin/purchase-orders/$id")({
    component: PurchaseOrderDetailRoute,
});

function PurchaseOrderDetailRoute() {
    const { id } = Route.useParams();

    return <PurchaseOrderDetailPage id={Number(id)} />;
}
