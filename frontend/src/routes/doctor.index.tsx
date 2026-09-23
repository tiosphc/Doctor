import { createFileRoute } from "@tanstack/react-router";
import { DoctorDashboardPage } from "@/pages/staff/StaffPages";

export const Route = createFileRoute("/doctor/")({ component: DoctorDashboardPage });
