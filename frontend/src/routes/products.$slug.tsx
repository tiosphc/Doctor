import { createFileRoute } from "@tanstack/react-router";
import { ProductDetailPage } from "@/pages/ProductPages";
export const Route = createFileRoute("/products/$slug")({
    component: ProductDetailRoute,
});

function ProductDetailRoute() {
    return <ProductDetailPage slug={Route.useParams().slug} />;
}
