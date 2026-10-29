import { createFileRoute } from "@tanstack/react-router";
import { NotificationsPage } from "@/pages/account/AccountPages";
import { AdminGuard } from "@/pages/admin/AdminPages";

export const Route = createFileRoute("/admin/notifications")({
    head: () => ({
        meta: [{ title: "Thông báo quản trị | Junie" }],
    }),
    component: AdminNotificationsPage,
});

function AdminNotificationsPage() {
    return (
        <AdminGuard>
            <NotificationsPage />
        </AdminGuard>
    );
}
