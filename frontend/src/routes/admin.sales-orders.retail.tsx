import { createFileRoute } from "@tanstack/react-router";
import { SalesOrderListPage } from "@/pages/admin/SalesOrderPages";

export const Route = createFileRoute("/admin/sales-orders/retail")({
    validateSearch: (search: Record<string, unknown>) => {
        const buyer =
            typeof search["buyer"] === "string" || typeof search["buyer"] === "number"
                ? Number(search["buyer"])
                : 0;
        return buyer > 0 ? { buyer } : {};
    },
    component: RetailSalesOrdersPage,
});

function RetailSalesOrdersPage() {
    const { buyer } = Route.useSearch();

    return <SalesOrderListPage channel="retail" {...(buyer ? { buyerUserId: buyer } : {})} />;
}
