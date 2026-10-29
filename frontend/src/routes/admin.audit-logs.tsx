import { createFileRoute } from "@tanstack/react-router";
import { AdminAuditLogPage } from "@/pages/admin/AdminAuditLogPage";

export const Route = createFileRoute("/admin/audit-logs")({
    head: () => ({ meta: [{ title: "Nhật ký hoạt động | Junie" }] }),
    component: AdminAuditLogPage,
});
