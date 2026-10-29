import { createFileRoute, Outlet } from "@tanstack/react-router";
import { RoleRouteGuard } from "@/components/layout/RoleRouteGuard";

export const Route = createFileRoute("/doctor")({
    component: () => (
        <RoleRouteGuard role="doctor">
            <Outlet />
        </RoleRouteGuard>
    ),
});
