import { createFileRoute } from "@tanstack/react-router";
import { DealerDashboardPage } from "@/features/dealers/DealerDashboardPage";

export const Route = createFileRoute("/dealer/")({ component: DealerDashboardPage });
