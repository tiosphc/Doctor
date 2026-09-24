import type { ReactNode } from "react";
import { Navigate } from "@tanstack/react-router";
import { useAuth } from "@/contexts/AuthContext";
import { LoadingState } from "@/components/common/AsyncState";

export function ProductAdminGuard({ children }: { children: ReactNode }) {
    const { user, isLoading } = useAuth();
    if (isLoading) return <LoadingState />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== "admin") return <Navigate to="/account" />;
    return children;
}

export const fieldClass = "w-full rounded-md border bg-background px-3 py-2";
export const buttonClass =
    "rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50";
export const secondaryButtonClass = "rounded-md border px-4 py-2 text-sm font-medium";
