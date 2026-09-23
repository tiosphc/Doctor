import { createFileRoute } from "@tanstack/react-router";
import { ServiceExplorerDetailPage } from "@/pages/ServiceExplorerDetailPage";

export const Route = createFileRoute("/services/$categorySlug/$serviceSlug")({
    head: () => ({ meta: [{ title: "Chi tiết dịch vụ | Junie" }] }),
    component: Page,
});

function Page() {
    const { categorySlug, serviceSlug } = Route.useParams();
    return <ServiceExplorerDetailPage categorySlug={categorySlug} serviceSlug={serviceSlug} />;
}
