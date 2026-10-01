import { createFileRoute } from "@tanstack/react-router";
import { PurchaseOrdersPage } from "@/pages/admin/PurchaseOrderPages";

export const Route = createFileRoute("/admin/purchase-orders/")({ component: PurchaseOrdersPage });
