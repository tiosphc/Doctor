import { apiRequest } from "./api";
import type {
    Appointment,
    AvailableSlots,
    PaginatedResponse,
    PublicAppointment,
    ResourceResponse,
} from "@/types";

export type BookingPayload = {
    doctor_id: number;
    service_id: number;
    appointment_date: string;
    start_time: string;
    note?: string;
    guest_name?: string;
    guest_email?: string;
    guest_phone?: string;
    verification_token?: string;
    voucher_id?: number;
};

export type RescheduleAppointmentPayload = {
    appointment_date: string;
    start_time: string;
};

export type RescheduleSlots = AvailableSlots & { appointment_id: number };
export type PublicRescheduleSlots = Omit<AvailableSlots, "doctor_id" | "service_id">;

export const appointmentApi = {
    requestOtp: (email: string) =>
        apiRequest<{ message: string }>("/api/guest-booking/request-otp", {
            method: "POST",
            body: { email },
        }),
    verifyOtp: (email: string, otp: string) =>
        apiRequest<{ message: string; verification_token: string }>(
            "/api/guest-booking/verify-otp",
            { method: "POST", body: { email, otp } },
        ),
    create: (payload: BookingPayload) =>
        apiRequest<ResourceResponse<Appointment>>("/api/appointments", {
            method: "POST",
            body: payload,
        }),
    lookup: (bookingCode: string, phone: string) =>
        apiRequest<ResourceResponse<PublicAppointment>>("/api/guest/appointments/lookup", {
            method: "POST",
            body: { booking_code: bookingCode, phone },
        }),
    publicRescheduleSlots: (bookingCode: string, phone: string, date: string) =>
        apiRequest<ResourceResponse<PublicRescheduleSlots>>(
            `/api/guest/appointments/${encodeURIComponent(bookingCode)}/reschedule-slots`,
            { method: "POST", body: { phone, date } },
        ),
    reschedulePublic: (bookingCode: string, phone: string, payload: RescheduleAppointmentPayload) =>
        apiRequest<ResourceResponse<PublicAppointment>>(
            `/api/guest/appointments/${encodeURIComponent(bookingCode)}/reschedule`,
            { method: "PATCH", body: { phone, ...payload } },
        ),
    cancelPublic: (bookingCode: string, phone: string) =>
        apiRequest<ResourceResponse<PublicAppointment>>(
            `/api/guest/appointments/${encodeURIComponent(bookingCode)}/cancel`,
            { method: "PATCH", body: { phone } },
        ),
    mine: (params: { status?: string; upcoming?: boolean; page?: number } = {}) =>
        apiRequest<PaginatedResponse<Appointment>>("/api/my-appointments", {
            query: {
                ...params,
                upcoming: params.upcoming === undefined ? undefined : params.upcoming ? 1 : 0,
            },
        }),
    mineDetail: (id: number | string) =>
        apiRequest<ResourceResponse<Appointment>>(`/api/my-appointments/${id}`),
    rescheduleSlots: (id: number | string, date: string) =>
        apiRequest<ResourceResponse<RescheduleSlots>>(
            `/api/my-appointments/${id}/reschedule-slots`,
            { query: { date } },
        ),
    rescheduleMine: (id: number | string, payload: RescheduleAppointmentPayload) =>
        apiRequest<ResourceResponse<Appointment>>(`/api/my-appointments/${id}/reschedule`, {
            method: "PATCH",
            body: payload,
        }),
    cancelMine: (id: number) =>
        apiRequest<ResourceResponse<Appointment>>(`/api/my-appointments/${id}/cancel`, {
            method: "PATCH",
        }),
};
