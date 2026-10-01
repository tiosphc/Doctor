import { createFileRoute } from "@tanstack/react-router";
import { DealerQuickOrderPage } from "@/features/dealers/DealerQuickOrderPage";

export const Route = createFileRoute("/dealer/quick-order/")({
    validateSearch: (search: Record<string, unknown>) => ({
        sku: typeof search["sku"] === "string" ? search["sku"] : "",
        reorder:
            Number.isSafeInteger(Number(search["reorder"])) && Number(search["reorder"]) > 0
                ? Number(search["reorder"])
                : 0,
    }),
    component: QuickOrderRoute,
});

function QuickOrderRoute() {
    const { sku, reorder } = Route.useSearch();
    return <DealerQuickOrderPage initialSearch={sku} reorderId={reorder} />;
}
