import { createFileRoute } from "@tanstack/react-router";
import { ReceptionistCustomersPage } from "@/pages/staff/StaffPages";

export const Route = createFileRoute("/receptionist/customers")({
    component: ReceptionistCustomersPage,
});
