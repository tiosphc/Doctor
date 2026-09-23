import { createFileRoute, Outlet } from "@tanstack/react-router";

export const Route = createFileRoute("/admin/appointments")({
    head: () => ({ meta: [{ title: "Quản lý lịch hẹn | Junie" }] }),
    component: AdminAppointmentsLayout,
});

function AdminAppointmentsLayout() {
    return <Outlet />;
}
