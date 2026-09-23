import { createFileRoute } from "@tanstack/react-router";
import { DoctorTodayPage } from "@/pages/staff/StaffPages";

export const Route = createFileRoute("/doctor/today")({ component: DoctorTodayPage });
