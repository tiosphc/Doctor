import { createFileRoute, Outlet } from "@tanstack/react-router";

export const Route = createFileRoute("/admin/doctors")({
    component: AdminDoctorsLayout,
});

function AdminDoctorsLayout() {
    return <Outlet />;
}
