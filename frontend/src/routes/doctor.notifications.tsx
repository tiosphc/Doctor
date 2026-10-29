import { createFileRoute } from "@tanstack/react-router";
import { NotificationsPage } from "@/pages/account/AccountPages";
import { StaffGuard } from "@/pages/staff/StaffPages";

export const Route = createFileRoute("/doctor/notifications")({
    head: () => ({
        meta: [{ title: "Thông báo bác sĩ | Junie" }],
    }),
    component: DoctorNotificationsPage,
});

function DoctorNotificationsPage() {
    return (
        <StaffGuard role="doctor">
            <NotificationsPage />
        </StaffGuard>
    );
}
