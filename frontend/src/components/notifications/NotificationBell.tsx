import { Bell, CheckCheck, LoaderCircle } from "lucide-react";
import { Link, useNavigate } from "@tanstack/react-router";
import { useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { notificationActionErrorMessage } from "@/services/notificationApi";
import {
    useMarkAllNotificationsRead,
    useMarkNotificationRead,
    useNotificationUnreadCount,
    useRecentNotifications,
    invalidateNotificationAppointment,
} from "@/hooks/useNotifications";
import { useAuth } from "@/contexts/AuthContext";
import { NotificationItem } from "./NotificationItem";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";

export function NotificationBell({ inverted = false }: { inverted?: boolean }) {
    const { user } = useAuth();
    const enabled = user !== null;
    const userId = user?.id ?? null;
    const [open, setOpen] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const unreadCount = useNotificationUnreadCount(userId);
    const recent = useRecentNotifications(userId, enabled && open);
    const markRead = useMarkNotificationRead(userId);
    const markAllRead = useMarkAllNotificationsRead(userId);

    if (!enabled) return null;

    const count = unreadCount.data ?? 0;
    const label = count > 0 ? `Thông báo, ${count} chưa đọc` : "Thông báo";

    async function openNotification(
        id: string,
        appointmentId: number | null,
        actionUrl: string | null,
    ) {
        setActionError(null);
        try {
            await markRead.mutateAsync(id);
        } catch (error) {
            setActionError(notificationActionErrorMessage(error));
            return;
        }

        setOpen(false);
        if (user && appointmentId !== null) {
            void invalidateNotificationAppointment(queryClient, user.role, appointmentId).catch(
                () => undefined,
            );
        }
        if (actionUrl?.includes("/account/vouchers")) {
            void navigate({ to: "/account/vouchers" });
        } else if (user?.role === "admin" && appointmentId !== null) {
            void navigate({
                to: "/admin/appointments/$id",
                params: { id: String(appointmentId) },
            });
        } else if (user?.role === "doctor" && appointmentId !== null) {
            void navigate({
                to: "/doctor/appointments/$id",
                params: { id: String(appointmentId) },
            });
        } else if (user?.role === "receptionist" && appointmentId !== null) {
            void navigate({
                to: "/receptionist/appointments/$id",
                params: { id: String(appointmentId) },
            });
        } else if (appointmentId !== null) {
            void navigate({
                to: "/account/appointments/$id",
                params: { id: String(appointmentId) },
            });
        } else if (actionUrl) {
            window.location.assign(actionUrl);
        }
    }

    async function markAll() {
        setActionError(null);
        try {
            await markAllRead.mutateAsync();
        } catch (error) {
            setActionError(notificationActionErrorMessage(error));
        }
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    aria-haspopup="dialog"
                    className={`focus-premium relative grid size-10 place-items-center rounded-full transition-colors ${inverted ? "text-white hover:bg-white/10" : "text-primary hover:bg-muted"}`}
                >
                    <Bell size={19} strokeWidth={1.8} />
                    {count > 0 && (
                        <span className="absolute right-0 top-0 min-w-4 translate-x-1/4 -translate-y-1/4 rounded-full border-2 border-background bg-secondary px-1 text-center text-[9px] font-bold leading-4 text-secondary-foreground shadow-sm">
                            {count > 99 ? "99+" : count}
                        </span>
                    )}
                </button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                sideOffset={12}
                className="w-[min(calc(100vw-2rem),25rem)] overflow-hidden rounded-2xl border-primary/10 bg-card p-0 shadow-2xl"
            >
                <div className="flex items-center justify-between gap-4 border-b border-border px-4 py-4">
                    <div>
                        <p className="label-luxury text-[10px]">Junie chăm sóc bạn</p>
                        <h2 className="mt-1 font-display text-xl text-primary">Thông báo</h2>
                    </div>
                    <button
                        type="button"
                        onClick={markAll}
                        disabled={markAllRead.isPending || count === 0}
                        className="focus-premium inline-flex items-center gap-1.5 text-[11px] font-semibold text-primary transition-colors hover:text-secondary disabled:cursor-not-allowed disabled:opacity-45"
                    >
                        <CheckCheck size={14} />
                        Đánh dấu tất cả đã đọc
                    </button>
                </div>
                {actionError && (
                    <p
                        role="alert"
                        className="border-b border-red-100 bg-red-50 px-4 py-3 text-xs text-red-700"
                    >
                        {actionError}
                    </p>
                )}
                <div className="max-h-[min(24rem,65vh)] overflow-y-auto px-1 py-1">
                    {recent.isPending ? (
                        <div className="flex items-center justify-center gap-2 px-4 py-10 text-xs text-muted-foreground">
                            <LoaderCircle size={16} className="animate-spin" />
                            Đang tải thông báo...
                        </div>
                    ) : recent.isError ? (
                        <div className="px-4 py-8 text-center text-xs text-muted-foreground">
                            Không thể tải thông báo. Vui lòng thử lại sau.
                        </div>
                    ) : recent.data.data.length === 0 ? (
                        <div className="px-4 py-10 text-center text-xs text-muted-foreground">
                            Bạn chưa có thông báo nào.
                        </div>
                    ) : (
                        recent.data.data.map((notification) => (
                            <NotificationItem
                                key={notification.id}
                                notification={notification}
                                compact
                                onSelect={() =>
                                    void openNotification(
                                        notification.id,
                                        notification.appointment_id,
                                        notification.action_url,
                                    )
                                }
                            />
                        ))
                    )}
                </div>
                <div className="border-t border-border px-4 py-3 text-center">
                    <Link
                        to={
                            user?.role === "admin"
                                ? "/admin/notifications"
                                : user?.role === "doctor"
                                  ? "/doctor/notifications"
                                  : user?.role === "receptionist"
                                    ? "/receptionist/notifications"
                                    : "/account/notifications"
                        }
                        onClick={() => setOpen(false)}
                        className="focus-premium text-xs font-semibold text-primary hover:text-secondary"
                    >
                        Xem tất cả thông báo
                    </Link>
                </div>
            </PopoverContent>
        </Popover>
    );
}
