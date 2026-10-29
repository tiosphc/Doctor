import { createFileRoute } from "@tanstack/react-router";
import { VouchersPage } from "@/pages/ReviewVoucherPages";

export const Route = createFileRoute("/account/vouchers")({
    head: () => ({ meta: [{ title: "Ưu đãi của tôi | Junie" }] }),
    component: VouchersPage,
});
