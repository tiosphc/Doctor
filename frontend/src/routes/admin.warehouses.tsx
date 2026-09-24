import { createFileRoute } from "@tanstack/react-router";
import { WarehousePage } from "@/pages/admin/WarehousePage";

export const Route = createFileRoute("/admin/warehouses")({ component: WarehousePage });
