import { createFileRoute, Outlet } from "@tanstack/react-router";

export const Route = createFileRoute("/services/$categorySlug")({
    component: CategoryLayout,
});

function CategoryLayout() {
    return <Outlet />;
}
