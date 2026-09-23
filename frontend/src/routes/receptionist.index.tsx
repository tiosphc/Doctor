import { createFileRoute } from "@tanstack/react-router";
import { ReceptionistDashboardPage } from "@/pages/staff/StaffPages";

export const Route = createFileRoute("/receptionist/")({ component: ReceptionistDashboardPage });
