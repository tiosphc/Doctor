import { createFileRoute } from "@tanstack/react-router";
import { SupplierPage } from "@/pages/admin/ProcurementPages";

export const Route = createFileRoute("/admin/suppliers")({ component: SupplierPage });
