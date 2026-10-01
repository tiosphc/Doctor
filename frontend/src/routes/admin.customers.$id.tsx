import { createFileRoute } from "@tanstack/react-router";
import { AdminCustomerDetailPage } from "@/pages/admin/AdminCustomerDetailPage";

export const Route = createFileRoute("/admin/customers/$id")({
    head: () => ({ meta: [{ title: "Chi tiết khách hàng | Junie" }] }),
    component: Page,
});

function Page() {
    const { id } = Route.useParams();

    return <AdminCustomerDetailPage id={Number(id)} />;
}
