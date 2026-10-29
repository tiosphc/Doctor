import { createFileRoute } from "@tanstack/react-router";
import { DealerOrderDetailPage } from "@/features/dealers/DealerOrderPages";

export const Route = createFileRoute("/dealer/orders/$orderId")({
    component: DetailRoute,
});

function DetailRoute() {
    const { orderId } = Route.useParams();
    return <DealerOrderDetailPage orderId={Number(orderId)} />;
}
