import { apiRequest } from "./api";
import type {
    Appointment,
    CanonicalCustomer,
    DoctorSchedule,
    PaginatedResponse,
    ResourceResponse,
    StaffDashboard,
    StaffUser,
} from "@/types";

export type StaffAppointmentParams = {
    page?: number | undefined;
    status?: string | undefined;
    date?: string | undefined;
    search?: string | undefined;
};

export type StaffAccountInput = {
    name: string;
    email: string;
    phone?: string;
    role: "receptionist";
    password?: string | undefined;
    password_confirmation?: string | undefined;
};

export type DoctorPortalSchedule = {
    schedules: DoctorSchedule[];
    time_offs: Array<{
        id: number;
        date: string;
        start_time: string | null;
        end_time: string | null;
        reason: string | null;
        full_day: boolean;
    }>;
    appointments: Appointment[];
};

export const adminStaffApi = {
    list: (params: { page?: number; search?: string; role?: string } = {}) =>
        apiRequest<PaginatedResponse<StaffUser>>("/api/admin/staff", { query: params }),
    create: (body: StaffAccountInput) =>
        apiRequest<ResourceResponse<StaffUser>>("/api/admin/staff", { method: "POST", body }),
    update: (id: number, body: Partial<StaffAccountInput>) =>
        apiRequest<ResourceResponse<StaffUser>>(`/api/admin/staff/${id}`, {
            method: "PATCH",
            body,
        }),
};

export const receptionistApi = {
    dashboard: () => apiRequest<StaffDashboard>("/api/receptionist/dashboard"),
    appointments: (params: StaffAppointmentParams = {}) =>
        apiRequest<PaginatedResponse<Appointment>>("/api/receptionist/appointments", {
            query: params,
        }),
    appointment: (id: number | string) =>
        apiRequest<ResourceResponse<Appointment>>(`/api/receptionist/appointments/${id}`),
    customers: (params: { page?: number | undefined; search?: string | undefined } = {}) =>
        apiRequest<PaginatedResponse<CanonicalCustomer>>("/api/receptionist/customers", {
            query: params,
        }),
    confirm: (id: number) => action<Appointment>(`/api/receptionist/appointments/${id}/confirm`),
    checkIn: (id: number) => action<Appointment>(`/api/receptionist/appointments/${id}/check-in`),
    complete: (id: number) => action<Appointment>(`/api/receptionist/appointments/${id}/complete`),
    noShow: (id: number) => action<Appointment>(`/api/receptionist/appointments/${id}/no-show`),
    cancel: (id: number) => action<Appointment>(`/api/receptionist/appointments/${id}/cancel`),
};

export const doctorPortalApi = {
    dashboard: () => apiRequest<StaffDashboard>("/api/doctor/dashboard"),
    appointments: (params: StaffAppointmentParams = {}) =>
        apiRequest<PaginatedResponse<Appointment>>("/api/doctor/appointments", { query: params }),
    appointment: (id: number | string) =>
        apiRequest<ResourceResponse<Appointment>>(`/api/doctor/appointments/${id}`),
    schedule: (params: { from?: string; to?: string } = {}) =>
        apiRequest<DoctorPortalSchedule>("/api/doctor/schedule", { query: params }),
    start: (id: number) => action<Appointment>(`/api/doctor/appointments/${id}/start`),
    completeTreatment: (id: number) =>
        action<Appointment>(`/api/doctor/appointments/${id}/complete`),
};

function action<T>(path: string) {
    return apiRequest<ResourceResponse<T>>(path, { method: "PATCH" });
}
