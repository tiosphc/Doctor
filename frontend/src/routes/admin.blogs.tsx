import { createFileRoute } from "@tanstack/react-router";
import { AdminBlogsPage } from "@/pages/BlogAdminPage";

export const Route = createFileRoute("/admin/blogs")({
    head: () => ({ meta: [{ title: "Danh sách bài viết | Junie" }] }),
    component: AdminBlogsPage,
});
