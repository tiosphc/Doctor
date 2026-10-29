import { apiRequest } from "./api";
import type {
    Appointment,
    Doctor,
    DoctorSchedule,
    DoctorTimeOff,
    PaginatedResponse,
    ResourceResponse,
    Service,
    User,
    Blog,
    BlogCategory,
    ServiceCategory,
    StaffUser,
    AdminCustomerDetail,
    AdminCustomerSummary,
    AdminCustomerPurchaseSummary,
    AdminCustomerPurchasedProduct,
    Voucher,
} from "@/types";
import type { StaffAccountInput } from "./staffApi";

export type AdminAppointmentQuickFilter =
    "all" | "new" | "upcoming" | "treatment_done" | "completed" | "cancelled";
export type AdminAppointmentSort = "nearest" | "newest" | "farthest" | "oldest";
export type AdminAppointmentCounts = {
    all: number;
    new: number;
    upcoming: number;
    treatment_done: number;
    completed: number;
    cancelled: number;
};
export type AdminAppointmentsResponse = PaginatedResponse<Appointment> & {
    counts: AdminAppointmentCounts;
};
export type AdminAppointmentParams = {
    status?: string | undefined;
    doctor_id?: string | number | undefined;
    service_id?: string | number | undefined;
    customer_id?: string | number | undefined;
    date?: string | undefined;
    from?: string | undefined;
    to?: string | undefined;
    quick_filter?: AdminAppointmentQuickFilter | undefined;
    sort?: AdminAppointmentSort | undefined;
    page?: number | undefined;
};

export type AdminDashboardPeriod = "7_days" | "30_days" | "month";
export type AuditLog = {
    id: number;
    actor_id: number | null;
    actor_name: string;
    actor_role: string;
    action: string;
    module: string;
    target_type: string;
    target_id: number | null;
    target_name: string | null;
    description: string;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    metadata: Record<string, unknown> | null;
    ip_address: string | null;
    user_agent: string | null;
    request_method: string | null;
    request_url: string | null;
    created_at: string;
};
export type AuditLogParams = {
    search?: string | undefined;
    actor_id?: number | undefined;
    role?: string | undefined;
    module?: string | undefined;
    action?: string | undefined;
    from?: string | undefined;
    to?: string | undefined;
    page?: number | undefined;
    per_page?: number | undefined;
};
export type AdminDashboardResponse = {
    generated_at: string;
    timezone: string;
    overview: {
        doctors: number;
        services: number;
        appointments: number;
        customers: number;
    };
    today: {
        date: string;
        appointments: number;
        waiting_checkin: number;
        checked_in: number;
        in_progress: number;
        completed: number;
        cancelled: number;
    };
    today_appointments: Array<{
        id: number;
        booking_code: string | null;
        customer_name: string | null;
        doctor_name: string;
        service_name: string;
        appointment_date: string;
        start_time: string;
        end_time: string;
        status: Appointment["status"];
    }>;
    appointment_statistics: {
        period: AdminDashboardPeriod;
        from: string;
        to: string;
        items: Array<{ date: string; total: number; completed: number; cancelled: number }>;
    };
    actions_required: Array<{
        key: string;
        label: string;
        count: number;
        url: string;
    }>;
    recent_activities: {
        available: boolean;
        items: Array<{
            id: string | number;
            occurred_at: string;
            actor: string;
            action: string;
            subject: string;
        }>;
    };
    doctor_today: Array<{
        id: number;
        name: string;
        appointments: number;
        in_progress: number;
        completed: number;
    }>;
    popular_services: Array<{
        id: number;
        name: string;
        appointments: number;
    }>;
};

export type DoctorInput = Pick<Doctor, "name" | "specialty"> & {
    bio?: string | null | undefined;
    phone?: string | null | undefined;
    email?: string | null | undefined;
    status?: Doctor["status"] | undefined;
};
export type DoctorCreateResponse = ResourceResponse<Doctor> & {
    message: string;
    invitation_queued: boolean;
};
export type ServiceCategoryInput = FormData;
export type ScheduleInput = Pick<DoctorSchedule, "day_of_week" | "start_time" | "end_time">;
export type ScheduleReplaceInput = ScheduleInput & { id?: number | undefined };
export type TimeOffInput = Pick<DoctorTimeOff, "date" | "start_time" | "end_time" | "reason">;
export type BlogInput = FormData;
export type BlogCategoryInput = Pick<BlogCategory, "name">;

export const adminApi = {
    dashboard: (period: AdminDashboardPeriod = "7_days") =>
        apiRequest<AdminDashboardResponse>("/api/admin/dashboard", { query: { period } }),
    auditLogs: (params: AuditLogParams = {}) =>
        apiRequest<PaginatedResponse<AuditLog>>("/api/admin/audit-logs", { query: params }),
    auditLog: (id: number) => apiRequest<ResourceResponse<AuditLog>>(`/api/admin/audit-logs/${id}`),
    staff: (
        params: {
            search?: string | undefined;
            role?: string | undefined;
            page?: number | undefined;
        } = {},
    ) => apiRequest<PaginatedResponse<StaffUser>>("/api/admin/staff", { query: params }),
    createStaff: (body: StaffAccountInput) =>
        apiRequest<ResourceResponse<StaffUser>>("/api/admin/staff", { method: "POST", body }),
    updateStaff: (id: number, body: Partial<StaffAccountInput>) =>
        apiRequest<ResourceResponse<StaffUser>>(`/api/admin/staff/${id}`, {
            method: "PATCH",
            body,
        }),
    deleteStaff: (id: number) =>
        apiRequest<{ message: string }>(`/api/admin/staff/${id}`, { method: "DELETE" }),
    doctors: (
        params: {
            search?: string | undefined;
            status?: Doctor["status"] | undefined;
            page?: number | undefined;
        } = {},
    ) => apiRequest<PaginatedResponse<Doctor>>("/api/admin/doctors", { query: params }),
    doctor: (id: number) => apiRequest<ResourceResponse<Doctor>>(`/api/admin/doctors/${id}`),
    createDoctor: (body: FormData) =>
        apiRequest<DoctorCreateResponse>("/api/admin/doctors", { method: "POST", body }),
    updateDoctor: (id: number, body: Partial<DoctorInput>) =>
        apiRequest<ResourceResponse<Doctor>>(`/api/admin/doctors/${id}`, { method: "PATCH", body }),
    updateDoctorProfile: (id: number, body: FormData) =>
        apiRequest<ResourceResponse<Doctor>>(`/api/admin/doctors/${id}`, { method: "POST", body }),
    deleteDoctor: (id: number) =>
        apiRequest<void>(`/api/admin/doctors/${id}`, { method: "DELETE" }),
    resendDoctorInvitation: (id: number) =>
        apiRequest<{ message: string }>(`/api/admin/doctors/${id}/resend-invitation`, {
            method: "POST",
        }),
    syncDoctorServices: (id: number, serviceIds: number[]) =>
        apiRequest<ResourceResponse<Doctor>>(`/api/admin/doctors/${id}/services`, {
            method: "PUT",
            body: { service_ids: serviceIds },
        }),
    schedules: (doctorId: number) =>
        apiRequest<{ data: DoctorSchedule[] }>(`/api/admin/doctors/${doctorId}/schedules`),
    replaceSchedules: (doctorId: number, schedules: ScheduleReplaceInput[]) =>
        apiRequest<{ data: DoctorSchedule[] }>(`/api/admin/doctors/${doctorId}/schedules`, {
            method: "PUT",
            body: { schedules },
        }),
    createSchedule: (doctorId: number, body: ScheduleInput) =>
        apiRequest<ResourceResponse<DoctorSchedule>>(`/api/admin/doctors/${doctorId}/schedules`, {
            method: "POST",
            body,
        }),
    updateSchedule: (doctorId: number, id: number, body: ScheduleInput) =>
        apiRequest<ResourceResponse<DoctorSchedule>>(
            `/api/admin/doctors/${doctorId}/schedules/${id}`,
            { method: "PATCH", body },
        ),
    deleteSchedule: (doctorId: number, id: number) =>
        apiRequest<void>(`/api/admin/doctors/${doctorId}/schedules/${id}`, { method: "DELETE" }),
    timeOffs: (
        doctorId: number,
        params: { from?: string | undefined; to?: string | undefined } = {},
    ) =>
        apiRequest<{ data: DoctorTimeOff[] }>(`/api/admin/doctors/${doctorId}/time-offs`, {
            query: params,
        }),
    createTimeOff: (doctorId: number, body: TimeOffInput) =>
        apiRequest<ResourceResponse<DoctorTimeOff>>(`/api/admin/doctors/${doctorId}/time-offs`, {
            method: "POST",
            body,
        }),
    updateTimeOff: (doctorId: number, id: number, body: TimeOffInput) =>
        apiRequest<ResourceResponse<DoctorTimeOff>>(
            `/api/admin/doctors/${doctorId}/time-offs/${id}`,
            { method: "PATCH", body },
        ),
    deleteTimeOff: (doctorId: number, id: number) =>
        apiRequest<void>(`/api/admin/doctors/${doctorId}/time-offs/${id}`, { method: "DELETE" }),
    services: (
        params: {
            search?: string | undefined;
            page?: number | undefined;
            per_page?: number | undefined;
        } = {},
    ) => apiRequest<PaginatedResponse<Service>>("/api/admin/services", { query: params }),
    service: (id: number) => apiRequest<ResourceResponse<Service>>(`/api/admin/services/${id}`),
    createService: (body: FormData) =>
        apiRequest<ResourceResponse<Service>>("/api/admin/services", { method: "POST", body }),
    updateService: (id: number, body: FormData) =>
        apiRequest<ResourceResponse<Service>>(`/api/admin/services/${id}`, {
            method: "POST",
            body,
        }),
    serviceCategories: () =>
        apiRequest<{ data: ServiceCategory[] }>("/api/admin/service-categories"),
    serviceCategoryPage: (page: number) =>
        apiRequest<PaginatedResponse<ServiceCategory>>("/api/admin/service-categories", {
            query: { page, per_page: 5 },
        }),
    createServiceCategory: (body: ServiceCategoryInput) =>
        apiRequest<ResourceResponse<ServiceCategory>>("/api/admin/service-categories", {
            method: "POST",
            body,
        }),
    appointments: (params: AdminAppointmentParams = {}) =>
        apiRequest<AdminAppointmentsResponse>("/api/admin/appointments", { query: params }),
    appointment: (id: number) =>
        apiRequest<ResourceResponse<Appointment>>(`/api/admin/appointments/${id}`),
    updateAppointmentStatus: (id: number, status: Appointment["status"]) =>
        apiRequest<ResourceResponse<Appointment>>(`/api/admin/appointments/${id}/status`, {
            method: "PATCH",
            body: { status },
        }),
    customers: (params: { search?: string | undefined; page?: number | undefined } = {}) =>
        apiRequest<PaginatedResponse<AdminCustomerSummary>>("/api/admin/customers", {
            query: params,
        }),
    customer: (id: number) =>
        apiRequest<ResourceResponse<AdminCustomerDetail>>(`/api/admin/customers/${id}`),
    customerPurchases: (id: number) =>
        apiRequest<ResourceResponse<AdminCustomerPurchaseSummary>>(
            `/api/admin/customers/${id}/purchases`,
        ),
    customerPurchasedProducts: (id: number, page: number) =>
        apiRequest<PaginatedResponse<AdminCustomerPurchasedProduct>>(
            `/api/admin/customers/${id}/purchased-products`,
            { query: { page } },
        ),
    customerVouchers: (id: number, page: number) =>
        apiRequest<PaginatedResponse<Voucher>>(`/api/admin/customers/${id}/vouchers`, {
            query: { page },
        }),
    blogs: (params: { search?: string | undefined; page?: number | undefined } = {}) =>
        apiRequest<PaginatedResponse<Blog>>("/api/admin/blogs", { query: params }),
    createBlog: (body: BlogInput) =>
        apiRequest<ResourceResponse<Blog>>("/api/admin/blogs", { method: "POST", body }),
    blogCategories: () => apiRequest<{ data: BlogCategory[] }>("/api/admin/blog-categories"),
    blogCategoryPage: (page: number) =>
        apiRequest<PaginatedResponse<BlogCategory>>("/api/admin/blog-categories", {
            query: { page, per_page: 5 },
        }),
    createBlogCategory: (body: BlogCategoryInput) =>
        apiRequest<ResourceResponse<BlogCategory>>("/api/admin/blog-categories", {
            method: "POST",
            body,
        }),
};
