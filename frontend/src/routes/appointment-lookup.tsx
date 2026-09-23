import { createFileRoute } from "@tanstack/react-router";
import { LookupPage } from "@/pages/LookupPage";
export const Route = createFileRoute("/appointment-lookup")({
    head: () => ({
        meta: [
            { title: "Tra cứu lịch hẹn | Junie" },
            {
                name: "description",
                content: "Tra cứu trạng thái lịch hẹn Junie bằng mã đặt lịch và số điện thoại.",
            },
            { property: "og:title", content: "Tra cứu lịch hẹn | Junie" },
            {
                property: "og:description",
                content: "Tra cứu trạng thái lịch hẹn Junie bằng mã đặt lịch và số điện thoại.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: LookupPage,
});
