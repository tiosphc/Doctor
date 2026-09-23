import { createFileRoute } from "@tanstack/react-router";
import { DoctorManagement } from "@/components/admin/DoctorManagement";
import { AdminGuard } from "@/pages/admin/AdminPages";
export const Route = createFileRoute("/admin/doctors/$id")({
    head: () => ({ meta: [{ title: "Chi tiết bác sĩ | Junie" }] }),
    component: Page,
});
function Page() {
    const { id } = Route.useParams();
    return (
        <AdminGuard>
            <DoctorManagement id={Number(id)} />
        </AdminGuard>
    );
}
