import { createFileRoute } from "@tanstack/react-router";
import { AdminServiceEditPage } from "@/pages/admin/ServicePages";

export const Route = createFileRoute("/admin/services/$id")({
    head: () => ({ meta: [{ title: "Chỉnh sửa dịch vụ | Junie" }] }),
    component: Page,
});

function Page() {
    const { id } = Route.useParams();
    return <AdminServiceEditPage id={Number(id)} />;
}
