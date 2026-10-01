import { createFileRoute } from "@tanstack/react-router";
import { CartPage } from "@/pages/RetailCommercePages";

export const Route = createFileRoute("/cart")({
    head: () => ({ meta: [{ title: "Giỏ hàng | Junie" }] }),
    component: CartPage,
});
