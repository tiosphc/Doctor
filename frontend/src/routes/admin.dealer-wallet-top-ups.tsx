import { createFileRoute } from "@tanstack/react-router";
import { AdminDealerWalletsPage } from "@/features/dealers/AdminDealerWalletsPage";

export const Route = createFileRoute("/admin/dealer-wallet-top-ups")({
    component: AdminDealerWalletsPage,
});
