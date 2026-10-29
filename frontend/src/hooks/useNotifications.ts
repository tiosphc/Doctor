import { useMutation, useQuery, useQueryClient, type QueryClient } from "@tanstack/react-query";
import { notificationApi, type NotificationListParams } from "@/services/notificationApi";
import type { CustomerNotification, PaginatedResponse, UserRole } from "@/types";

export const notificationQueryKeys = {
    all: ["notifications"] as const,
    user: (userId: number) => [...notificationQueryKeys.all, "user", userId] as const,
    listRoot: (userId: number) => [...notificationQueryKeys.user(userId), "list"] as const,
    list: (userId: number, params: NotificationListParams = {}) =>
        [...notificationQueryKeys.listRoot(userId), params] as const,
    recent: (userId: number) => [...notificationQueryKeys.user(userId), "recent"] as const,
    unreadCount: (userId: number) =>
        [...notificationQueryKeys.user(userId), "unread-count"] as const,
};

export function useNotificationUnreadCount(userId: number | null) {
    return useQuery({
        queryKey: notificationQueryKeys.unreadCount(userId ?? 0),
        queryFn: async () => (await notificationApi.unreadCount()).data.count,
        enabled: userId !== null,
        staleTime: 30_000,
        refetchInterval: userId !== null ? 15_000 : false,
        retry: false,
    });
}

export function useRecentNotifications(userId: number | null, enabled: boolean) {
    return useQuery({
        queryKey: notificationQueryKeys.recent(userId ?? 0),
        queryFn: () => notificationApi.list({ page: 1, per_page: 5 }),
        enabled: userId !== null && enabled,
        staleTime: 30_000,
        refetchInterval: enabled ? 15_000 : false,
        retry: false,
    });
}

export async function invalidateNotificationAppointment(
    queryClient: QueryClient,
    role: UserRole,
    appointmentId: number,
) {
    if (role === "admin") {
        await Promise.all([
            queryClient.invalidateQueries({ queryKey: ["admin-appointment", appointmentId] }),
            queryClient.invalidateQueries({ queryKey: ["admin-appointments"] }),
        ]);
        return;
    }

    if (role === "doctor" || role === "receptionist") {
        await Promise.all([
            queryClient.invalidateQueries({ queryKey: [role, "appointment", appointmentId] }),
            queryClient.invalidateQueries({ queryKey: [role, "appointments"] }),
            queryClient.invalidateQueries({ queryKey: [role, "dashboard"] }),
        ]);
        return;
    }

    await Promise.all([
        queryClient.invalidateQueries({ queryKey: ["my-appointment", appointmentId] }),
        queryClient.invalidateQueries({ queryKey: ["my-appointments"] }),
    ]);
}

export function useNotifications(
    userId: number | null,
    params: NotificationListParams,
    enabled: boolean,
) {
    return useQuery({
        queryKey: notificationQueryKeys.list(userId ?? 0, params),
        queryFn: () => notificationApi.list(params),
        enabled: userId !== null && enabled,
        staleTime: 30_000,
        retry: false,
    });
}

function markReadInPage(
    page: PaginatedResponse<CustomerNotification> | undefined,
    id: string,
    readAt: string,
): PaginatedResponse<CustomerNotification> | undefined {
    if (!page) return page;
    return {
        ...page,
        data: page.data.map((notification) =>
            notification.id === id && notification.read_at === null
                ? { ...notification, read_at: readAt }
                : notification,
        ),
    };
}

function markAllReadInPage(
    page: PaginatedResponse<CustomerNotification> | undefined,
    readAt: string,
): PaginatedResponse<CustomerNotification> | undefined {
    if (!page) return page;
    return {
        ...page,
        data: page.data.map((notification) => ({
            ...notification,
            read_at: notification.read_at ?? readAt,
        })),
    };
}

export function useMarkNotificationRead(userId: number | null) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id: string) => notificationApi.markRead(id),
        onSuccess: (response, id) => {
            const readAt = response.read_at ?? new Date().toISOString();
            if (userId === null) return;

            const cachedPages = queryClient.getQueriesData<PaginatedResponse<CustomerNotification>>(
                {
                    queryKey: notificationQueryKeys.listRoot(userId),
                },
            );
            const recentNotifications = queryClient.getQueryData<
                PaginatedResponse<CustomerNotification>
            >(notificationQueryKeys.recent(userId));
            const wasUnread =
                cachedPages.some(([, page]) =>
                    page?.data.some(
                        (notification) => notification.id === id && notification.read_at === null,
                    ),
                ) ||
                recentNotifications?.data.some(
                    (notification) => notification.id === id && notification.read_at === null,
                ) ||
                false;

            queryClient.setQueriesData<PaginatedResponse<CustomerNotification>>(
                { queryKey: notificationQueryKeys.listRoot(userId) },
                (page) => markReadInPage(page, id, readAt),
            );
            queryClient.setQueryData<PaginatedResponse<CustomerNotification>>(
                notificationQueryKeys.recent(userId),
                (page) => markReadInPage(page, id, readAt),
            );
            if (wasUnread) {
                queryClient.setQueryData<number>(
                    notificationQueryKeys.unreadCount(userId),
                    (count) => Math.max(0, (count ?? 0) - 1),
                );
            }

            void Promise.all([
                queryClient.invalidateQueries({
                    queryKey: notificationQueryKeys.listRoot(userId),
                }),
                queryClient.invalidateQueries({ queryKey: notificationQueryKeys.recent(userId) }),
                queryClient.invalidateQueries({
                    queryKey: notificationQueryKeys.unreadCount(userId),
                }),
            ]).catch(() => undefined);
        },
    });
}

export function useMarkAllNotificationsRead(userId: number | null) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: () => notificationApi.markAllRead(),
        onSuccess: () => {
            if (userId === null) return;

            const readAt = new Date().toISOString();
            queryClient.setQueriesData<PaginatedResponse<CustomerNotification>>(
                { queryKey: notificationQueryKeys.listRoot(userId) },
                (page) => markAllReadInPage(page, readAt),
            );
            queryClient.setQueryData<PaginatedResponse<CustomerNotification>>(
                notificationQueryKeys.recent(userId),
                (page) => markAllReadInPage(page, readAt),
            );
            queryClient.setQueryData(notificationQueryKeys.unreadCount(userId), 0);

            void Promise.all([
                queryClient.invalidateQueries({
                    queryKey: notificationQueryKeys.listRoot(userId),
                }),
                queryClient.invalidateQueries({ queryKey: notificationQueryKeys.recent(userId) }),
                queryClient.invalidateQueries({
                    queryKey: notificationQueryKeys.unreadCount(userId),
                }),
            ]).catch(() => undefined);
        },
    });
}
