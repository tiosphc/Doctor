import { apiRequest } from "./api";
import type {
    PaginatedResponse,
    ResourceResponse,
    Service,
    ServiceCategoryExplorer,
} from "@/types";

export const serviceApi = {
    list: (params: { search?: string | undefined; page?: number | undefined } = {}) =>
        apiRequest<PaginatedResponse<Service>>("/api/services", { query: params }),
    find: (id: number | string) => apiRequest<ResourceResponse<Service>>(`/api/services/${id}`),
    categories: () => apiRequest<{ data: ServiceCategoryExplorer[] }>("/api/service-categories"),
    category: (categorySlug: string) =>
        apiRequest<ResourceResponse<ServiceCategoryExplorer>>(
            `/api/service-categories/${encodeURIComponent(categorySlug)}`,
        ),
    service: (categorySlug: string, serviceSlug: string) =>
        apiRequest<ResourceResponse<Service>>(
            `/api/service-categories/${encodeURIComponent(categorySlug)}/services/${encodeURIComponent(serviceSlug)}`,
        ),
};
