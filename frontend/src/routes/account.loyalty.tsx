import { createFileRoute } from "@tanstack/react-router";
import { LoyaltyPage } from "@/pages/account/AccountPages";
export const Route = createFileRoute("/account/loyalty")({
    head: () => ({
        meta: [
            { title: "Quyền lợi thành viên | Junie" },
            {
                name: "description",
                content: "Theo dõi tiến trình và quyền lợi khách hàng thân thiết tại Junie.",
            },
            { property: "og:title", content: "Quyền lợi thành viên | Junie" },
            {
                property: "og:description",
                content: "Theo dõi tiến trình và quyền lợi khách hàng thân thiết tại Junie.",
            },
            { property: "og:type", content: "website" },
            { name: "twitter:card", content: "summary_large_image" },
        ],
    }),
    component: LoyaltyPage,
});
