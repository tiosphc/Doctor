import { createFileRoute, Outlet } from "@tanstack/react-router";

export const Route = createFileRoute("/account/appointments")({
    component: AppointmentsLayout,
});

function AppointmentsLayout() {
    return <Outlet />;
}
