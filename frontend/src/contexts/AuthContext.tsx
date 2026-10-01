import { useQuery, useQueryClient } from "@tanstack/react-query";
import { createContext, useContext, useState, type ReactNode } from "react";
import {
    authApi,
    type LoginPayload,
    type RegisterPayload,
    type UpdateProfilePayload,
} from "@/services/authApi";
import type { User } from "@/types";

type AuthContextValue = {
    user: User | null;
    isAuthenticated: boolean;
    isLoading: boolean;
    login: (payload: LoginPayload) => Promise<User>;
    register: (payload: RegisterPayload) => Promise<User>;
    updateProfile: (payload: UpdateProfilePayload) => Promise<User>;
    logout: () => Promise<void>;
    refreshUser: () => Promise<User | null>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
    const queryClient = useQueryClient();
    const [isMutating, setIsMutating] = useState(false);
    const userQuery = useQuery({
        queryKey: ["auth-user"],
        queryFn: authApi.current,
        enabled: typeof window !== "undefined",
        retry: false,
        staleTime: 60_000,
    });

    async function runAuth(action: () => Promise<User>): Promise<User> {
        setIsMutating(true);
        try {
            const user = await action();
            queryClient.setQueryData(["auth-user"], user);
            return user;
        } finally {
            setIsMutating(false);
        }
    }

    const value: AuthContextValue = {
        user: userQuery.data ?? null,
        isAuthenticated: Boolean(userQuery.data),
        isLoading: userQuery.isPending || isMutating,
        login: (payload) => runAuth(() => authApi.login(payload)),
        register: (payload) => runAuth(() => authApi.register(payload)),
        updateProfile: async (payload) => {
            const user = await authApi.updateProfile(payload);
            queryClient.setQueryData(["auth-user"], user);
            return user;
        },
        logout: async () => {
            setIsMutating(true);
            try {
                await authApi.logout();
                queryClient.setQueryData(["auth-user"], null);
                queryClient.removeQueries({ queryKey: ["my-appointments"] });
                queryClient.removeQueries({ queryKey: ["notifications"] });
                queryClient.removeQueries({ queryKey: ["retail-cart"] });
                queryClient.removeQueries({ queryKey: ["retail-checkout-review"] });
                queryClient.removeQueries({ queryKey: ["retail-orders"] });
                queryClient.removeQueries({ queryKey: ["retail-order"] });
                queryClient.removeQueries({ queryKey: ["dealer-application-mine"] });
                queryClient.removeQueries({ queryKey: ["dealer-accounts-mine"] });
                queryClient.removeQueries({ queryKey: ["dealer-account"] });
                queryClient.removeQueries({ queryKey: ["dealer-tier"] });
                queryClient.removeQueries({ queryKey: ["dealer-wallet"] });
                queryClient.removeQueries({ queryKey: ["admin-dealer-tier"] });
                queryClient.removeQueries({ queryKey: ["admin-dealer-tier-history"] });
                queryClient.removeQueries({ queryKey: ["admin-dealer-tier-overrides"] });
            } finally {
                setIsMutating(false);
            }
        },
        refreshUser: async () => {
            const user = await authApi.current();
            queryClient.setQueryData(["auth-user"], user);
            return user;
        },
    };

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
    const context = useContext(AuthContext);
    if (!context) throw new Error("useAuth must be used inside AuthProvider");
    return context;
}
