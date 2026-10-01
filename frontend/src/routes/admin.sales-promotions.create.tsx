import { createFileRoute } from "@tanstack/react-router";
import { SalesPromotionPage } from "@/pages/admin/SalesPromotionPage";

export const Route = createFileRoute("/admin/sales-promotions/create")({
    component: () => <SalesPromotionPage mode="create" />,
});
