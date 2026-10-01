import { createFileRoute } from "@tanstack/react-router";
import { AdminDealerApplicationsPage } from "@/features/dealer-applications/AdminDealerApplicationsPage";

export const Route = createFileRoute("/admin/dealer-applications/")({
    component: AdminDealerApplicationsPage,
});
