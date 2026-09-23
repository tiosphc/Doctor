import { createFileRoute } from "@tanstack/react-router";
import { AdminServiceCreatePage } from "@/pages/admin/ServicePages";
export const Route = createFileRoute("/admin/services")({
    head: () => ({ meta: [{ title: "Danh sách dịch vụ | Junie" }] }),
    component: AdminServiceCreatePage,
});
