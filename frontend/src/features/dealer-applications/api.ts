import { apiRequest } from "@/services/api";
import type { PaginatedResponse, ResourceResponse } from "@/types";
import type { DealerApplication, DealerApplicationInput, DealerApplicationStatus } from "./types";

export const dealerApplicationKeys = {
    mine: (userId?: number) => ["dealer-application-mine", userId] as const,
    adminList: (filters: object) => ["admin-dealer-applications", filters] as const,
    adminDetail: (id: number) => ["admin-dealer-application", id] as const,
};

export const dealerApplicationApi = {
    mine: () => apiRequest<ResourceResponse<DealerApplication>>("/api/dealer-applications/my"),
    submit: (body: DealerApplicationInput) =>
        apiRequest<ResourceResponse<DealerApplication>>("/api/dealer-applications", {
            method: "POST",
            body,
        }),
    adminList: (filters: {
        status?: DealerApplicationStatus | "";
        search?: string;
        page?: number;
        from?: string;
        to?: string;
    }) =>
        apiRequest<PaginatedResponse<DealerApplication>>("/api/admin/dealer-applications", {
            query: filters,
        }),
    adminDetail: (id: number) =>
        apiRequest<ResourceResponse<DealerApplication>>(`/api/admin/dealer-applications/${id}`),
    approve: (id: number) =>
        apiRequest<ResourceResponse<DealerApplication>>(
            `/api/admin/dealer-applications/${id}/approve`,
            { method: "POST" },
        ),
    reject: (id: number, rejection_reason: string) =>
        apiRequest<ResourceResponse<DealerApplication>>(
            `/api/admin/dealer-applications/${id}/reject`,
            { method: "POST", body: { rejection_reason } },
        ),
};
