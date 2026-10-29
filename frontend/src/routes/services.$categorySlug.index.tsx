import { createFileRoute } from "@tanstack/react-router";
import { ServiceCategoryPage } from "@/pages/ServicesPage";

export const Route = createFileRoute("/services/$categorySlug/")({
    component: Page,
});

function Page() {
    const { categorySlug } = Route.useParams();
    return <ServiceCategoryPage slug={categorySlug} />;
}
