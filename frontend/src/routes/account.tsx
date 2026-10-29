import { createFileRoute, Outlet } from "@tanstack/react-router";
import { RoleRouteGuard } from "@/components/layout/RoleRouteGuard";

export const Route = createFileRoute("/account")({
    component: AccountLayout,
});

function AccountLayout() {
    return (
        <RoleRouteGuard role="customer">
            <Outlet />
        </RoleRouteGuard>
    );
}
