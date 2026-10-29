import { createFileRoute } from "@tanstack/react-router";
import { LoginPage } from "@/pages/AuthPages";
export const Route = createFileRoute("/login")({
    head: () => ({
        meta: [
            { title: "Đăng nhập | Junie" },
            { name: "description", content: "Đăng nhập tài khoản khách hàng Junie." },
            { property: "og:title", content: "Đăng nhập | Junie" },
            { property: "og:description", content: "Đăng nhập tài khoản khách hàng Junie." },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: LoginPage,
});
