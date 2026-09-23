import { createFileRoute } from "@tanstack/react-router";
import { DoctorSchedulePage } from "@/pages/staff/StaffPages";

export const Route = createFileRoute("/doctor/schedule")({ component: DoctorSchedulePage });
