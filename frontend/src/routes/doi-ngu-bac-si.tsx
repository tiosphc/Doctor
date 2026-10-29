import { createFileRoute } from "@tanstack/react-router";
import { DoctorTeamPage } from "@/pages/DoctorTeamPage";

export const Route = createFileRoute("/doi-ngu-bac-si")({
    head: () => ({
        meta: [
            { title: "Đội ngũ bác sĩ | Junie" },
            {
                name: "description",
                content: "Tìm hiểu hồ sơ bác sĩ, lĩnh vực chuyên môn và dịch vụ tư vấn tại Junie.",
            },
            { property: "og:title", content: "Đội ngũ bác sĩ | Junie" },
            { property: "og:type", content: "website" },
        ],
    }),
    component: DoctorTeamPage,
});
