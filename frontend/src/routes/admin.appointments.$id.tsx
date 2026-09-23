import { createFileRoute } from "@tanstack/react-router";
import { AdminAppointmentDetailPage } from "@/pages/admin/AdminPages";

export const Route = createFileRoute("/admin/appointments/$id")({
    head: () => ({ meta: [{ title: "Chi tiết lịch hẹn | Junie" }] }),
    component: Page,
});

function Page() {
    const { id } = Route.useParams();
    return <AdminAppointmentDetailPage id={Number(id)} />;
}
