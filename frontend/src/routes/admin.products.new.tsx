import { createFileRoute } from "@tanstack/react-router";
import { ProductWizardPage } from "@/pages/admin/ProductWizardPage";

export const Route = createFileRoute("/admin/products/new")({
    validateSearch: (search: Record<string, unknown>): { draft?: number } => {
        const draft = Number(search["draft"]);
        return Number.isSafeInteger(draft) && draft > 0 ? { draft } : {};
    },
    component: function NewProductRoute() {
        const { draft } = Route.useSearch();
        return <ProductWizardPage {...(draft ? { draft } : {})} />;
    },
});
