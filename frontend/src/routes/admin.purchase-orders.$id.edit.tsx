import { createFileRoute } from "@tanstack/react-router";
import { PurchaseOrderFormPage } from "@/pages/admin/PurchaseOrderPages";

export const Route = createFileRoute("/admin/purchase-orders/$id/edit")({
    component: PurchaseOrderEditRoute,
});

function PurchaseOrderEditRoute() {
    const { id } = Route.useParams();

    return <PurchaseOrderFormPage id={Number(id)} />;
}
