import { createFileRoute, Outlet } from "@tanstack/react-router";

export const Route = createFileRoute("/doctor/appointments")({
    component: DoctorAppointmentsLayout,
});

function DoctorAppointmentsLayout() {
    return <Outlet />;
}
