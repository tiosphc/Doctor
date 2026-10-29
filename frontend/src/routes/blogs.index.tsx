import { createFileRoute } from "@tanstack/react-router";
import { BlogsPage } from "@/pages/BlogPages";

export const Route = createFileRoute("/blogs/")({
    head: () => ({
        meta: [
            { title: "Kiến thức & Tin tức | Junie" },
            {
                name: "description",
                content: "Kiến thức chăm sóc da chuẩn khoa học từ đội ngũ Junie.",
            },
        ],
    }),
    component: BlogsPage,
});
