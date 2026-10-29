import { createFileRoute } from "@tanstack/react-router";
import { AdminBlogCategoriesPage } from "@/pages/BlogCategoriesAdminPage";

export const Route = createFileRoute("/admin/blog-categories")({
    head: () => ({ meta: [{ title: "Thêm danh mục | Junie" }] }),
    component: AdminBlogCategoriesPage,
});
