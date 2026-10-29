import { useQuery } from "@tanstack/react-query";
import { doctorApi } from "@/services/doctorApi";

export function useFeaturedDoctor() {
    const query = useQuery({
        queryKey: ["doctors", "featured"],
        queryFn: () => doctorApi.list(),
    });

    return {
        query,
        doctor: query.data?.data[0],
        doctors: query.data?.data ?? [],
    };
}
