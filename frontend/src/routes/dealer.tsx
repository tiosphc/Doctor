import { createFileRoute, Outlet } from "@tanstack/react-router";
import { RoleRouteGuard } from "@/components/layout/RoleRouteGuard";
import { DealerRouteGuard } from "@/features/dealers/DealerRouteGuard";

export const Route = createFileRoute("/dealer")({
    component: () => (
        <RoleRouteGuard role="customer">
            <DealerRouteGuard>
                <Outlet />
            </DealerRouteGuard>
        </RoleRouteGuard>
    ),
});
