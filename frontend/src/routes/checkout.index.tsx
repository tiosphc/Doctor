import { createFileRoute } from "@tanstack/react-router";
import { CheckoutPage } from "@/pages/RetailCommercePages";

export const Route = createFileRoute("/checkout/")({
    head: () => ({ meta: [{ title: "Đặt hàng | Junie" }] }),
    component: CheckoutPage,
});
