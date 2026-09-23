import { apiRequest } from "./api";
import type { AvailableSlots, Doctor, PaginatedResponse, ResourceResponse } from "@/types";

export const doctorApi = {
    list: (
        params: {
            search?: string | undefined;
            service_id?: number | undefined;
            booking?: boolean | undefined;
            page?: number | undefined;
        } = {},
    ) => apiRequest<PaginatedResponse<Doctor>>("/api/doctors", { query: params }),
    find: (id: number | string) => apiRequest<ResourceResponse<Doctor>>(`/api/doctors/${id}`),
    availableSlots: (doctorId: number, serviceId: number, date: string) =>
        apiRequest<ResourceResponse<AvailableSlots>>(`/api/doctors/${doctorId}/available-slots`, {
            query: { service_id: serviceId, date },
        }),
};
