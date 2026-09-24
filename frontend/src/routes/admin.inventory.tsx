import { createFileRoute } from "@tanstack/react-router";
import { InventoryPage } from "@/pages/admin/InventoryPage";

export const Route = createFileRoute("/admin/inventory")({ component: InventoryPage });
