import type { ReactNode } from "react";
import { useQuery } from "@tanstack/react-query";
import { Navigate, useRouterState } from "@tanstack/react-router";
import { ErrorState, LoadingState } from "@/components/common/AsyncState";
import { useAuth } from "@/contexts/AuthContext";
import { errorMessage } from "@/services/api";
import { dealerApi, dealerKeys } from "./api";

export function DealerRouteGuard({ children }: { children: ReactNode }) {
    const path = useRouterState({ select: (state) => state.location.pathname });
    const { user } = useAuth();
    const accounts = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled: path !== "/dealer/apply",
    });

    if (path === "/dealer/apply") return children;
    if (accounts.isPending) return <LoadingState label="Đang kiểm tra tài khoản đại lý..." />;
    if (accounts.isError) {
        return (
            <ErrorState
                message={errorMessage(accounts.error)}
                retry={() => void accounts.refetch()}
            />
        );
    }
    if (!accounts.data.data.length) return <Navigate to="/account" />;

    return children;
}
