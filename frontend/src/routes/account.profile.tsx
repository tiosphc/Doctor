import { createFileRoute } from "@tanstack/react-router";
import { ProfilePage } from "@/pages/account/AccountPages";
export const Route = createFileRoute("/account/profile")({
    head: () => ({
        meta: [
            { title: "Thông tin cá nhân | Junie" },
            { name: "description", content: "Cập nhật thông tin cá nhân trong tài khoản Junie." },
            { property: "og:title", content: "Thông tin cá nhân | Junie" },
            {
                property: "og:description",
                content: "Cập nhật thông tin cá nhân trong tài khoản Junie.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: ProfilePage,
});
