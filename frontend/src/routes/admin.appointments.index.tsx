import { createFileRoute } from "@tanstack/react-router";
import { AdminAppointmentsPage } from "@/pages/admin/AdminPages";

export const Route = createFileRoute("/admin/appointments/")({
    validateSearch: (search: Record<string, unknown>) => {
        const customer =
            typeof search["customer"] === "string" || typeof search["customer"] === "number"
                ? Number(search["customer"])
                : 0;
        return customer > 0 ? { customer } : {};
    },
    component: AppointmentsPage,
});

function AppointmentsPage() {
    const { customer } = Route.useSearch();

    return <AdminAppointmentsPage {...(customer ? { customerUserId: customer } : {})} />;
}
