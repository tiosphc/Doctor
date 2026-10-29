import { createFileRoute } from "@tanstack/react-router";
import { DoctorsPage } from "@/pages/DoctorsPage";
export const Route = createFileRoute("/doctors")({
    head: () => ({
        meta: [
            { title: "Đội ngũ bác sĩ | Junie" },
            {
                name: "description",
                content: "Gặp gỡ đội ngũ bác sĩ da liễu thẩm mỹ giàu kinh nghiệm tại Junie.",
            },
            { property: "og:title", content: "Đội ngũ bác sĩ | Junie" },
            {
                property: "og:description",
                content: "Gặp gỡ đội ngũ bác sĩ da liễu thẩm mỹ giàu kinh nghiệm tại Junie.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: DoctorsPage,
});
