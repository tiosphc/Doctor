import { createFileRoute } from "@tanstack/react-router";
import { BookingSuccessPage } from "@/pages/BookingSuccessPage";
export const Route = createFileRoute("/booking/success")({
    head: () => ({
        meta: [
            { title: "Đặt lịch thành công | Junie" },
            { name: "description", content: "Lịch hẹn tại Junie đã được ghi nhận thành công." },
            { property: "og:title", content: "Đặt lịch thành công | Junie" },
            {
                property: "og:description",
                content: "Lịch hẹn tại Junie đã được ghi nhận thành công.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: BookingSuccessPage,
});
