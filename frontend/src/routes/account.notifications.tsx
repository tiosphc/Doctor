import { createFileRoute } from "@tanstack/react-router";
import { CustomerGuard, NotificationsPage } from "@/pages/account/AccountPages";

export const Route = createFileRoute("/account/notifications")({
    head: () => ({
        meta: [
            { title: "Thông báo | Junie" },
            {
                name: "description",
                content: "Theo dõi cập nhật lịch hẹn và thông tin chăm sóc tại Junie.",
            },
            { property: "og:title", content: "Thông báo | Junie" },
            {
                property: "og:description",
                content: "Theo dõi cập nhật lịch hẹn và thông tin chăm sóc tại Junie.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: CustomerNotificationsPage,
});

function CustomerNotificationsPage() {
    return (
        <CustomerGuard>
            <NotificationsPage />
        </CustomerGuard>
    );
}
