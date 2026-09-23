import { createFileRoute } from "@tanstack/react-router";
import { AdminDoctorsPage } from "@/pages/admin/AdminPages";

export const Route = createFileRoute("/admin/doctors/")({
    validateSearch: (search: Record<string, unknown>): { notice?: string } =>
        typeof search["notice"] === "string" ? { notice: search["notice"] } : {},
    head: () => ({ meta: [{ title: "Quản lý bác sĩ | Junie" }] }),
    component: AdminDoctorsPage,
});
