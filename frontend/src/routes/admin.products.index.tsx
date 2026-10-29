import { createFileRoute } from "@tanstack/react-router";
import { AdminProductsPage } from "@/pages/admin/ProductAdminPages";
export const Route = createFileRoute("/admin/products/")({ component: AdminProductsPage });
