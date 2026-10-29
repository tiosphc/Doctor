import type { ReactNode } from "react";
import { Navigate } from "@tanstack/react-router";
import { LoadingState } from "@/components/common/AsyncState";
import { useAuth } from "@/contexts/AuthContext";
import type { UserRole } from "@/types";

const homeByRole: Record<UserRole, string> = {
    admin: "/admin",
    customer: "/account",
    doctor: "/doctor",
    receptionist: "/receptionist",
};

export function RoleRouteGuard({ role, children }: { role: UserRole; children: ReactNode }) {
    const { user, isLoading } = useAuth();

    if (isLoading) return <LoadingState label="Đang kiểm tra quyền truy cập..." />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== role) return <Navigate to={homeByRole[user.role]} />;

    return children;
}
