import { createFileRoute } from "@tanstack/react-router";
import { DealerOrderImportPage } from "@/features/dealers/DealerOrderImportPage";

export const Route = createFileRoute("/dealer/import-orders")({ component: DealerOrderImportPage });
