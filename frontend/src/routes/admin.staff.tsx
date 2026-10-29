import { createFileRoute } from "@tanstack/react-router";
import { AdminStaffPage } from "@/pages/admin/StaffAdminPage";

export const Route = createFileRoute("/admin/staff")({ component: AdminStaffPage });
