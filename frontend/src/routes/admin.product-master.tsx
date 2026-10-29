import { createFileRoute } from "@tanstack/react-router";
import { ProductMasterPage } from "@/pages/admin/ProductMasterPage";
export const Route = createFileRoute("/admin/product-master")({ component: ProductMasterPage });
