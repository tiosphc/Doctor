import { createFileRoute } from "@tanstack/react-router";
import { AppointmentDetailPage } from "@/pages/account/AccountPages";
export const Route = createFileRoute("/account/appointments/$id")({
    head: () => ({ meta: [{ title: "Chi tiết lịch hẹn | Junie" }] }),
    component: Page,
});
function Page() {
    const { id } = Route.useParams();
    return <AppointmentDetailPage id={id} />;
}
