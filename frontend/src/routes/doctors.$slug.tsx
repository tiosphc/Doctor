import { createFileRoute } from "@tanstack/react-router";
import { DoctorDetailPage } from "@/pages/DetailPages";
export const Route = createFileRoute("/doctors/$slug")({
    head: () => ({
        meta: [
            { title: "Hồ sơ bác sĩ | Junie" },
            { name: "description", content: "Chuyên môn và kinh nghiệm bác sĩ Junie." },
            { property: "og:title", content: "Bác sĩ Junie" },
            { property: "og:description", content: "Đội ngũ bác sĩ da liễu thẩm mỹ tận tâm." },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: Page,
});
function Page() {
    const { slug } = Route.useParams();
    return <DoctorDetailPage slug={slug} />;
}
