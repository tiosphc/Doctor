import { createFileRoute } from "@tanstack/react-router";
import { AppointmentsPage } from "@/pages/account/AccountPages";

export const Route = createFileRoute("/account/appointments/")({
    head: () => ({
        meta: [
            { title: "Lịch hẹn của tôi | Junie" },
            { name: "description", content: "Quản lý các lịch hẹn sắp tới và đã hoàn thành." },
            { property: "og:title", content: "Lịch hẹn của tôi | Junie" },
            {
                property: "og:description",
                content: "Quản lý các lịch hẹn sắp tới và đã hoàn thành.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: AppointmentsPage,
});
