import { createFileRoute } from "@tanstack/react-router";
import { DealerPricingPage } from "@/pages/admin/RetailPricingPage";

export const Route = createFileRoute("/admin/dealer-pricing")({ component: DealerPricingPage });
