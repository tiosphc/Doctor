import { ApiError, apiRequest, csrfCookie } from "./api";
import type { ResourceResponse, User } from "@/types";

export type LoginPayload = { email: string; password: string };
export type RegisterPayload = {
    name: string;
    email: string;
    phone?: string;
    password: string;
    password_confirmation: string;
};
export type UpdateProfilePayload = { name: string; phone: string };
export type SetupDoctorPasswordPayload = {
    email: string;
    token: string;
    password: string;
    password_confirmation: string;
};

export const authApi = {
    async current(): Promise<User | null> {
        try {
            return (await apiRequest<ResourceResponse<User>>("/api/user")).data;
        } catch (error) {
            if (error instanceof ApiError && error.status === 401) return null;
            throw error;
        }
    },
    async login(payload: LoginPayload): Promise<User> {
        await csrfCookie();
        await apiRequest<ResourceResponse<User>>("/api/login", { method: "POST", body: payload });
        const user = await this.current();
        if (!user) throw new ApiError(401, "Không thể xác nhận phiên đăng nhập.");
        return user;
    },
    async register(payload: RegisterPayload): Promise<User> {
        await csrfCookie();
        await apiRequest<ResourceResponse<User>>("/api/register", {
            method: "POST",
            body: payload,
        });
        const user = await this.current();
        if (!user) throw new ApiError(401, "Không thể xác nhận tài khoản vừa tạo.");
        return user;
    },
    async updateProfile(payload: UpdateProfilePayload): Promise<User> {
        const response = await apiRequest<ResourceResponse<User>>("/api/user", {
            method: "PATCH",
            body: payload,
        });
        return response.data;
    },
    async setupDoctorPassword(payload: SetupDoctorPasswordPayload): Promise<string> {
        const response = await apiRequest<{ message: string }>("/api/auth/setup-password", {
            method: "POST",
            body: payload,
        });
        return response.message;
    },
    async logout(): Promise<void> {
        await apiRequest<{ message: string }>("/api/logout", { method: "POST" });
    },
};
