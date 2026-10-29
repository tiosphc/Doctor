import { createFileRoute } from "@tanstack/react-router";
import { DashboardPage } from "@/pages/account/AccountPages";

export const Route = createFileRoute("/account/")({
    head: () => ({
        meta: [
            { title: "Tài khoản của tôi | Junie" },
            { name: "description", content: "Tổng quan lịch hẹn và quyền lợi thành viên Junie." },
            { property: "og:title", content: "Tài khoản của tôi | Junie" },
            {
                property: "og:description",
                content: "Tổng quan lịch hẹn và quyền lợi thành viên Junie.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: DashboardPage,
});
