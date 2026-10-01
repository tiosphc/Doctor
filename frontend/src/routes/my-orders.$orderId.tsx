import { createFileRoute } from "@tanstack/react-router";
import { OrderDetailPage } from "@/pages/RetailCommercePages";

export const Route = createFileRoute("/my-orders/$orderId")({
    head: () => ({ meta: [{ title: "Chi tiết đơn hàng | Junie" }] }),
    component: MyOrderDetailRoute,
});

function MyOrderDetailRoute() {
    return <OrderDetailPage orderId={Number(Route.useParams().orderId)} />;
}
