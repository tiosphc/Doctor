import { createFileRoute } from "@tanstack/react-router";
import { DealerProductsPage } from "@/features/dealers/DealerProductsPage";

export const Route = createFileRoute("/dealer/products/")({ component: DealerProductsPage });
