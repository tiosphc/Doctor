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

export const fieldClass =
    "block min-h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-normal text-foreground outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-60";
export const buttonClass =
    "inline-flex min-h-10 items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30 disabled:opacity-50";
export const secondaryButtonClass =
    "inline-flex min-h-10 items-center justify-center rounded-md border px-4 py-2 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30";
