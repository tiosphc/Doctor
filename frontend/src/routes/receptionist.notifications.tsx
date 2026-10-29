import { createFileRoute } from "@tanstack/react-router";
import { NotificationsPage } from "@/pages/account/AccountPages";
import { StaffGuard } from "@/pages/staff/StaffPages";

export const Route = createFileRoute("/receptionist/notifications")({
    head: () => ({
        meta: [{ title: "Thông báo lễ tân | Junie" }],
    }),
    component: ReceptionistNotificationsPage,
});

function ReceptionistNotificationsPage() {
    return (
        <StaffGuard role="receptionist">
            <NotificationsPage />
        </StaffGuard>
    );
}
