import { useEffect } from "react";
import { useQuery } from "@tanstack/react-query";
import { Container } from "@/components/common/Container";
import { ErrorState, LoadingState } from "@/components/common/AsyncState";
import { ServiceDetailView } from "@/components/services/ServiceDetailView";
import { errorMessage } from "@/services/api";
import { serviceApi } from "@/services/serviceApi";

export function ServiceExplorerDetailPage({
    categorySlug,
    serviceSlug,
}: {
    categorySlug: string;
    serviceSlug: string;
}) {
    const serviceQuery = useQuery({
        queryKey: ["service-detail", categorySlug, serviceSlug],
        queryFn: () => serviceApi.service(categorySlug, serviceSlug),
        retry: false,
    });
    const categoryQuery = useQuery({
        queryKey: ["service-category", categorySlug],
        queryFn: () => serviceApi.category(categorySlug),
        retry: false,
    });
    const service = serviceQuery.data?.data;

    useEffect(() => {
        if (!service || typeof document === "undefined") return;
        const previousTitle = document.title;
        const meta = document.querySelector('meta[name="description"]');
        const createdMeta = !meta;
        const previousDescription = meta?.getAttribute("content");
        const description =
            service.seo_description ||
            service.short_description ||
            service.description ||
            "Tìm hiểu thêm về dịch vụ tại Junie.";
        const descriptionMeta = meta ?? document.head.appendChild(document.createElement("meta"));
        descriptionMeta.setAttribute("name", "description");
        descriptionMeta.setAttribute("content", description);
        document.title = service.seo_title || `${service.name} | Junie`;
        return () => {
            document.title = previousTitle;
            if (createdMeta) {
                descriptionMeta.remove();
            } else if (typeof previousDescription === "string") {
                descriptionMeta.setAttribute("content", previousDescription);
            } else {
                descriptionMeta.removeAttribute("content");
            }
        };
    }, [service]);

    if (serviceQuery.isPending) {
        return (
            <Container className="section-space">
                <LoadingState label="Đang tải thông tin dịch vụ..." />
            </Container>
        );
    }
    if (serviceQuery.isError || !service) {
        return (
            <Container className="section-space">
                <ErrorState
                    message={errorMessage(serviceQuery.error)}
                    retry={() => serviceQuery.refetch()}
                />
            </Container>
        );
    }

    const category = categoryQuery.data?.data;
    const relatedServices = category?.services.filter((item) => item.id !== service.id) ?? [];
    return (
        <ServiceDetailView
            service={service}
            category={category}
            relatedServices={relatedServices}
        />
    );
}
