import { createFileRoute } from "@tanstack/react-router";
import { DoctorReviewsPage } from "@/pages/ReviewVoucherPages";

export const Route = createFileRoute("/doctor/reviews")({
    head: () => ({ meta: [{ title: "Đánh giá về tôi | Junie" }] }),
    component: DoctorReviewsPage,
});
