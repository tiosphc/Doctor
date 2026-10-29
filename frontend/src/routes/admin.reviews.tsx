import { createFileRoute } from "@tanstack/react-router";
import { AdminReviewsPage } from "@/pages/ReviewVoucherPages";

export const Route = createFileRoute("/admin/reviews")({
    head: () => ({ meta: [{ title: "Quản lý đánh giá | Junie" }] }),
    component: AdminReviewsPage,
});
