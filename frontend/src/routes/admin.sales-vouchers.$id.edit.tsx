import { createFileRoute } from "@tanstack/react-router";
import { SalesVoucherPage } from "@/pages/admin/SalesVoucherPage";

export const Route = createFileRoute("/admin/sales-vouchers/$id/edit")({
    component: EditVoucherRoute,
});

function EditVoucherRoute() {
    const { id } = Route.useParams();
    return <SalesVoucherPage mode="edit" voucherId={Number(id)} />;
}
