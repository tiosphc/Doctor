import { createFileRoute } from "@tanstack/react-router";
import { AdminDealerDetailPage } from "@/features/dealers/AdminDealersPage";

export const Route = createFileRoute("/admin/dealers/$id")({
    component: DealerDetailRoute,
    validateSearch: (search: Record<string, unknown>): { tab?: "wallet" } =>
        search["tab"] === "wallet" ? { tab: "wallet" } : {},
});

function DealerDetailRoute() {
    const { id } = Route.useParams();
    const { tab } = Route.useSearch();
    return (
        <AdminDealerDetailPage
            id={Number(id)}
            initialTab={tab === "wallet" ? "wallet" : "overview"}
        />
    );
}
