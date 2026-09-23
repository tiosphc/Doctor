import { createFileRoute } from "@tanstack/react-router";
import { StaffAppointmentDetailPage } from "@/pages/staff/StaffPages";

export const Route = createFileRoute("/doctor/appointments/$id")({
    component: () => <StaffAppointmentDetailPage role="doctor" />,
});
