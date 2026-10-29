import { createFileRoute, Outlet } from "@tanstack/react-router";
import { RoleRouteGuard } from "@/components/layout/RoleRouteGuard";

export const Route = createFileRoute("/admin")({
    component: AdminLayout,
});

function AdminLayout() {
    return (
        <RoleRouteGuard role="admin">
            <Outlet />
        </RoleRouteGuard>
    );
}
