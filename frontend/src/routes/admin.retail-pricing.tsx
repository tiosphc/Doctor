import { createFileRoute } from "@tanstack/react-router";
import { RetailPricingPage } from "@/pages/admin/RetailPricingPage";
export const Route = createFileRoute("/admin/retail-pricing")({ component: RetailPricingPage });
