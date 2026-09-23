import { createFileRoute } from "@tanstack/react-router";
import { ReceptionistAppointmentsPage } from "@/pages/staff/StaffPages";

export const Route = createFileRoute("/receptionist/appointments/")({
    component: ReceptionistAppointmentsPage,
});
