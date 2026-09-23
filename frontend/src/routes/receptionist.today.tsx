import { createFileRoute } from "@tanstack/react-router";
import { ReceptionistTodayPage } from "@/pages/staff/StaffPages";

export const Route = createFileRoute("/receptionist/today")({ component: ReceptionistTodayPage });
