import { createFileRoute, Outlet } from "@tanstack/react-router";

export const Route = createFileRoute("/blogs")({
    component: BlogLayout,
});

function BlogLayout() {
    return <Outlet />;
}
