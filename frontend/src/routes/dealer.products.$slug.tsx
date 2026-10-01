import { createFileRoute } from "@tanstack/react-router";
import { DealerProductDetailPage } from "@/features/dealers/DealerProductsPage";

export const Route = createFileRoute("/dealer/products/$slug")({
    component: DealerProductRoute,
});

function DealerProductRoute() {
    const { slug } = Route.useParams();
    return <DealerProductDetailPage slug={slug} />;
}
