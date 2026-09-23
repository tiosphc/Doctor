import { createFileRoute, Outlet } from "@tanstack/react-router";
export const Route = createFileRoute("/services")({
    head: () => ({
        meta: [
            { title: "Dịch vụ thẩm mỹ y khoa | Junie" },
            {
                name: "description",
                content: "Khám phá các dịch vụ da liễu thẩm mỹ cá nhân hóa tại Junie.",
            },
            { property: "og:title", content: "Dịch vụ thẩm mỹ y khoa | Junie" },
            {
                property: "og:description",
                content: "Khám phá các dịch vụ da liễu thẩm mỹ cá nhân hóa tại Junie.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: ServicesLayout,
});

function ServicesLayout() {
    return <Outlet />;
}
