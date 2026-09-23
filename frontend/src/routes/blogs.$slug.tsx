import { createFileRoute } from "@tanstack/react-router";
import { BlogDetailPage } from "@/pages/BlogPages";

export const Route = createFileRoute("/blogs/$slug")({
    head: () => ({
        meta: [
            { title: "Bài viết | Junie" },
            { name: "description", content: "Kiến thức chăm sóc da từ Junie." },
        ],
    }),
    component: Page,
});

function Page() {
    const { slug } = Route.useParams();
    return <BlogDetailPage slug={slug} />;
}
