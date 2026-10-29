import { createFileRoute } from "@tanstack/react-router";
import { AdminProductDetailPage } from "@/pages/admin/ProductAdminPages";
export const Route = createFileRoute("/admin/products/$id")({
    component: AdminProductDetailRoute,
});

function AdminProductDetailRoute() {
    return <AdminProductDetailPage id={Number(Route.useParams().id)} />;
}
