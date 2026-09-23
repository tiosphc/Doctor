import { createFileRoute } from "@tanstack/react-router";
import { BookingPage } from "@/pages/BookingPage";
export const Route = createFileRoute("/booking")({
    validateSearch: (search: Record<string, unknown>): { service_id?: number } => {
        const raw = search["service_id"];
        const serviceId = typeof raw === "string" || typeof raw === "number" ? Number(raw) : NaN;
        return Number.isFinite(serviceId) ? { service_id: serviceId } : {};
    },
    head: () => ({
        meta: [
            { title: "Đặt lịch khám da | Junie" },
            {
                name: "description",
                content: "Đặt lịch thăm khám da liễu thẩm mỹ trực tuyến, không cần đăng nhập.",
            },
            { property: "og:title", content: "Đặt lịch khám da | Junie" },
            {
                property: "og:description",
                content: "Đặt lịch thăm khám da liễu thẩm mỹ trực tuyến, không cần đăng nhập.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: Page,
});
function Page() {
    const { service_id } = Route.useSearch();
    return (
        <div className="min-h-[calc(100svh-4rem)] bg-[#f0f3f5] md:min-h-[calc(100svh-5rem)]">
            <BookingPage {...(service_id !== undefined ? { initialServiceId: service_id } : {})} />
        </div>
    );
}
