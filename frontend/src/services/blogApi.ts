import { apiRequest } from "./api";
import type { Blog, PaginatedResponse, ResourceResponse } from "@/types";

export const blogApi = {
    list: (params: { page?: number | undefined; per_page?: number | undefined } = {}) =>
        apiRequest<PaginatedResponse<Blog>>("/api/blogs", { query: params }),
    find: (slug: string) => apiRequest<ResourceResponse<Blog>>(`/api/blogs/${slug}`),
};
