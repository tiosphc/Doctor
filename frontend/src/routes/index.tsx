import { createFileRoute } from "@tanstack/react-router";
import { LovableHomePage } from "@/pages/LovableHomePage";

export const Route = createFileRoute("/")({
    head: () => ({
        meta: [
            { title: "Junie — Vẻ đẹp riêng. Một cách nâng niu." },
            {
                name: "description",
                content: "Chăm sóc da cá nhân hóa và đặt lịch trực tuyến tại Junie.",
            },
            { property: "og:title", content: "Junie Aesthetic & Dermatology" },
            {
                property: "og:description",
                content: "Vẻ đẹp tự nhiên bắt đầu từ sự chăm sóc đúng cách.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: LovableHomePage,
});
