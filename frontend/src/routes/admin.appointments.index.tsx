import { createFileRoute } from "@tanstack/react-router";
import { AdminAppointmentsPage } from "@/pages/admin/AdminPages";

export const Route = createFileRoute("/admin/appointments/")({
    component: AdminAppointmentsPage,
});
