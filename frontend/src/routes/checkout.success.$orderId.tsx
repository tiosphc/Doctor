import { createFileRoute } from "@tanstack/react-router";
import { CheckoutSuccessPage } from "@/pages/RetailCommercePages";

export const Route = createFileRoute("/checkout/success/$orderId")({
    head: () => ({ meta: [{ title: "Đặt hàng thành công | Junie" }] }),
    component: CheckoutSuccessRoute,
});

function CheckoutSuccessRoute() {
    return <CheckoutSuccessPage orderId={Number(Route.useParams().orderId)} />;
}
