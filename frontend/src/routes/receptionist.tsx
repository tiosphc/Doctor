import { createFileRoute, Outlet } from "@tanstack/react-router";
import { RoleRouteGuard } from "@/components/layout/RoleRouteGuard";

export const Route = createFileRoute("/receptionist")({
    component: () => (
        <RoleRouteGuard role="receptionist">
            <Outlet />
        </RoleRouteGuard>
    ),
});
