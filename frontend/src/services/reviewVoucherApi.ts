import { apiRequest } from "./api";
import type { PaginatedResponse, ResourceResponse, Review, Voucher } from "@/types";

export type ReviewInput = { rating: number; comment?: string | null };
export type AdminVoucherInput = { code: string; value: number; expires_at: string };

export const reviewApi = {
    create: (appointmentId: number, body: ReviewInput) =>
        apiRequest<{ data: Review; voucher: Voucher | null }>(
            `/api/my-appointments/${appointmentId}/review`,
            { method: "POST", body },
        ),
    update: (reviewId: number, body: ReviewInput) =>
        apiRequest<ResourceResponse<Review>>(`/api/my-reviews/${reviewId}`, {
            method: "PATCH",
            body,
        }),
    doctor: (doctorId: number, page = 1) =>
        apiRequest<PaginatedResponse<Review>>(`/api/doctors/${doctorId}/reviews`, {
            query: { page },
        }),
    admin: (params: {
        page?: number;
        rating?: number;
        doctor_id?: number;
        service_id?: number;
        status?: string;
    }) => apiRequest<PaginatedResponse<Review>>("/api/admin/reviews", { query: params }),
    moderate: (reviewId: number, status: "published" | "hidden") =>
        apiRequest<ResourceResponse<Review>>(`/api/admin/reviews/${reviewId}`, {
            method: "PATCH",
            body: { status },
        }),
    doctorPortal: (page = 1) =>
        apiRequest<PaginatedResponse<Review>>("/api/doctor/reviews", { query: { page } }),
};

export const voucherApi = {
    mine: (params: { page?: number; per_page?: number; status?: string } = {}) =>
        apiRequest<PaginatedResponse<Voucher>>("/api/my-vouchers", { query: params }),
    resolve: (code: string) =>
        apiRequest<ResourceResponse<Voucher>>("/api/my-vouchers/resolve", {
            method: "POST",
            body: { code },
        }),
    admin: (
        params: {
            page?: number;
            search?: string;
            status?: string;
            source?: Voucher["source"];
        } = {},
    ) => apiRequest<PaginatedResponse<Voucher>>("/api/admin/vouchers", { query: params }),
    generateAdminCode: () => apiRequest<{ code: string }>("/api/admin/vouchers/generate-code"),
    createAdmin: (body: AdminVoucherInput) =>
        apiRequest<ResourceResponse<Voucher>>("/api/admin/vouchers", { method: "POST", body }),
    revoke: (voucherId: number) =>
        apiRequest<ResourceResponse<Voucher>>(`/api/admin/vouchers/${voucherId}/revoke`, {
            method: "PATCH",
        }),
    remove: (voucherId: number) =>
        apiRequest<{ message: string }>(`/api/admin/vouchers/${voucherId}`, {
            method: "DELETE",
        }),
};
