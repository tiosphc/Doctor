import { createFileRoute } from "@tanstack/react-router";
import { AdminDealerApplicationDetailPage } from "@/features/dealer-applications/AdminDealerApplicationsPage";

export const Route = createFileRoute("/admin/dealer-applications/$id")({
    component: DealerApplicationDetailRoute,
});

function DealerApplicationDetailRoute() {
    const { id } = Route.useParams();
    return <AdminDealerApplicationDetailPage id={Number(id)} />;
}
