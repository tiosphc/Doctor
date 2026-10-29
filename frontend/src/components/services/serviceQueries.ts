import { useQuery } from "@tanstack/react-query";
import { serviceApi } from "@/services/serviceApi";

export const serviceCategoriesQueryKey = ["service-categories"] as const;

export function useServiceCategories() {
    return useQuery({
        queryKey: serviceCategoriesQueryKey,
        queryFn: () => serviceApi.categories(),
        staleTime: 5 * 60 * 1000,
    });
}
