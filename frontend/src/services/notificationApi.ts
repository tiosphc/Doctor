import { ApiError, apiRequest } from "./api";
import type { CustomerNotification, PaginatedResponse } from "@/types";

export type NotificationListParams = {
    page?: number;
    per_page?: number;
};

export type NotificationUnreadCountResponse = { data: { count: number } };
export type NotificationReadAllResponse = { data: { updated: number; unread_count: 0 } };

export const notificationApi = {
    list: (params: NotificationListParams = {}) =>
        apiRequest<PaginatedResponse<CustomerNotification>>("/api/notifications", {
            query: params,
        }),
    unreadCount: () =>
        apiRequest<NotificationUnreadCountResponse>("/api/notifications/unread-count"),
    markRead: (id: string) =>
        apiRequest<CustomerNotification>(`/api/notifications/${encodeURIComponent(id)}/read`, {
            method: "PATCH",
        }),
    markAllRead: () =>
        apiRequest<NotificationReadAllResponse>("/api/notifications/read-all", {
            method: "PATCH",
        }),
};

export function notificationActionErrorMessage(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return "Không thể kết nối máy chủ. Vui lòng thử lại.";
    }

    const messages: Partial<Record<number, string>> = {
        401: "Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.",
        403: "Bạn không có quyền thao tác với thông báo này.",
        404: "Thông báo không còn tồn tại hoặc không thuộc tài khoản của bạn.",
        419: "Phiên bảo mật đã hết hạn. Vui lòng thử lại.",
        422: "Không thể cập nhật thông báo vì dữ liệu chưa hợp lệ.",
        500: "Đã xảy ra lỗi khi xử lý thông báo.",
    };

    return messages[error.status] ?? error.message;
}
