import { createFileRoute } from "@tanstack/react-router";
import { ForgotPasswordPage } from "@/pages/AuthPages";
export const Route = createFileRoute("/forgot-password")({
    head: () => ({
        meta: [
            { title: "Quên mật khẩu | Junie" },
            { name: "description", content: "Nhận hướng dẫn đặt lại mật khẩu tài khoản Junie." },
            { property: "og:title", content: "Quên mật khẩu | Junie" },
            {
                property: "og:description",
                content: "Nhận hướng dẫn đặt lại mật khẩu tài khoản Junie.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: ForgotPasswordPage,
});
