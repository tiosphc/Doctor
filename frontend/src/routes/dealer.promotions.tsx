import { createFileRoute } from "@tanstack/react-router";
import { GiftPromotionsPage } from "@/pages/GiftPromotionsPage";

export const Route = createFileRoute("/dealer/promotions")({
    head: () => ({ meta: [{ title: "Ưu đãi đại lý | Junie" }] }),
    component: () => <GiftPromotionsPage dealer />,
});
