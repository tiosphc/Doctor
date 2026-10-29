import { createFileRoute } from "@tanstack/react-router";
import { PurchaseOrderFormPage } from "@/pages/admin/PurchaseOrderPages";

export const Route = createFileRoute("/admin/purchase-orders/new")({
    component: PurchaseOrderFormPage,
});
