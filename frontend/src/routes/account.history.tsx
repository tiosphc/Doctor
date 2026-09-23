import { createFileRoute } from "@tanstack/react-router";
import { HistoryPage } from "@/pages/account/AccountPages";
export const Route = createFileRoute("/account/history")({
    head: () => ({
        meta: [
            { title: "Lịch sử dịch vụ | Junie" },
            { name: "description", content: "Theo dõi các dịch vụ đã thực hiện tại Junie." },
            { property: "og:title", content: "Lịch sử dịch vụ | Junie" },
            {
                property: "og:description",
                content: "Theo dõi các dịch vụ đã thực hiện tại Junie.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: HistoryPage,
});
