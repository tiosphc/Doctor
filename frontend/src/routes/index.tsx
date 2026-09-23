import { createFileRoute } from "@tanstack/react-router";
import { HomePage } from "@/pages/HomePage";

export const Route = createFileRoute("/")({
    head: () => ({
        meta: [
            { title: "Phòng khám da liễu thẩm mỹ Junie" },
            {
                name: "description",
                content: "Chăm sóc da chuẩn khoa học, tôn vinh vẻ đẹp tự nhiên tại Junie.",
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
    component: HomePage,
});
