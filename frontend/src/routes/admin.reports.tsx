import { createFileRoute } from "@tanstack/react-router";
import { ErpReportsPage } from "@/pages/admin/ErpReportsPage";

export const Route = createFileRoute("/admin/reports")({
    component: ErpReportsPage,
});
