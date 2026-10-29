import { createFileRoute } from "@tanstack/react-router";
import { DealerTierPage } from "@/pages/admin/DealerTierPage";

export const Route = createFileRoute("/admin/dealer-tiers")({ component: DealerTierPage });
