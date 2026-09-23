import { createFileRoute } from "@tanstack/react-router";
import { AdminServiceCategoriesPage } from "@/pages/ServiceCategoriesAdminPage";

export const Route = createFileRoute("/admin/service-categories")({
    head: () => ({ meta: [{ title: "Danh mục dịch vụ | Junie" }] }),
    component: AdminServiceCategoriesPage,
});
