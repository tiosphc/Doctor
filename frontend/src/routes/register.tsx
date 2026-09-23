import { createFileRoute } from "@tanstack/react-router";
import { RegisterPage } from "@/pages/AuthPages";
export const Route = createFileRoute("/register")({
    head: () => ({
        meta: [
            { title: "Tạo tài khoản | Junie" },
            {
                name: "description",
                content: "Tạo tài khoản để quản lý hành trình chăm sóc tại Junie.",
            },
            { property: "og:title", content: "Tạo tài khoản | Junie" },
            {
                property: "og:description",
                content: "Tạo tài khoản để quản lý hành trình chăm sóc tại Junie.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: RegisterPage,
});
