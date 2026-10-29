import { createFileRoute } from "@tanstack/react-router";
import { MyOrdersPage } from "@/pages/RetailCommercePages";

export const Route = createFileRoute("/my-orders/")({
    head: () => ({ meta: [{ title: "Đơn hàng của tôi | Junie" }] }),
    component: MyOrdersPage,
});
