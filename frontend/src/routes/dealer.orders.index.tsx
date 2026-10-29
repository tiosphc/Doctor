import { createFileRoute } from "@tanstack/react-router";
import { DealerOrdersPage } from "@/features/dealers/DealerOrderPages";

export const Route = createFileRoute("/dealer/orders/")({ component: DealerOrdersPage });
