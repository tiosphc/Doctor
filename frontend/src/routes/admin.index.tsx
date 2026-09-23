import { createFileRoute } from "@tanstack/react-router";
import { AdminDashboardPage } from "@/pages/admin/AdminDashboardPage";

export const Route = createFileRoute("/admin/")({
    head: () => ({
        meta: [{ title: "Quản trị | Junie" }],
    }),
    component: AdminDashboardPage,
});
