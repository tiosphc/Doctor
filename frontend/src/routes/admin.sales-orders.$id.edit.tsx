import { createFileRoute } from "@tanstack/react-router";
import { SalesOrderCreatePage } from "@/pages/admin/RetailOrderCreatePage";

export const Route = createFileRoute("/admin/sales-orders/$id/edit")({
    component: RetailDraftEditRoute,
});

function RetailDraftEditRoute() {
    const { id } = Route.useParams();

    return <SalesOrderCreatePage draftId={Number(id)} />;
}
