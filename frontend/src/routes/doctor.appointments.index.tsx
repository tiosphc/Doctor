import { createFileRoute } from "@tanstack/react-router";
import { DoctorAppointmentsPage } from "@/pages/staff/StaffPages";

export const Route = createFileRoute("/doctor/appointments/")({
    component: DoctorAppointmentsPage,
});
