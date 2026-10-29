import { createFileRoute } from "@tanstack/react-router";
import { SalesPromotionPage } from "@/pages/admin/SalesPromotionPage";

export const Route = createFileRoute("/admin/sales-promotions/$id/edit")({
    component: EditPromotionRoute,
});

function EditPromotionRoute() {
    const { id } = Route.useParams();
    return <SalesPromotionPage mode="edit" promotionId={Number(id)} />;
}
