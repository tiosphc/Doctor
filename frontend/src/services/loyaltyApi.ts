import { apiRequest } from "./api";
import type { LoyaltySummary, ResourceResponse } from "@/types";

export const loyaltyApi = {
    mine: () => apiRequest<ResourceResponse<LoyaltySummary>>("/api/my-loyalty"),
};
