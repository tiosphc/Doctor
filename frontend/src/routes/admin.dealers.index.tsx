import { createFileRoute } from "@tanstack/react-router";
import { AdminDealersPage } from "@/features/dealers/AdminDealersPage";

export const Route = createFileRoute("/admin/dealers/")({ component: AdminDealersPage });
