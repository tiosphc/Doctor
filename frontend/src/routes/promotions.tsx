import { createFileRoute } from "@tanstack/react-router";
import { GiftPromotionsPage } from "@/pages/GiftPromotionsPage";

export const Route = createFileRoute("/promotions")({
    head: () => ({ meta: [{ title: "Ưu đãi sản phẩm | Junie" }] }),
    component: GiftPromotionsPage,
});
