import { Bell, CalendarCheck2, CalendarClock, CalendarX2, CheckCircle2, Gift } from "lucide-react";
import type { CustomerNotification } from "@/types";
import { formatNotificationTime } from "@/lib/notification-time";

function notificationIcon(event: CustomerNotification["event"]) {
    if (event === "appointment_confirmed") return CheckCircle2;
    if (event === "appointment_rescheduled") return CalendarClock;
    if (event === "appointment_cancelled") return CalendarX2;
    if (event === "appointment_reminder") return CalendarCheck2;
    if (event === "appointment_treatment_done") return CheckCircle2;
    if (event === "loyalty_milestone_reward") return Gift;
    return Bell;
}

export function NotificationItem({
    notification,
    onSelect,
    compact = false,
}: {
    notification: CustomerNotification;
    onSelect: () => void;
    compact?: boolean;
}) {
    const Icon = notificationIcon(notification.event);
    const unread = notification.read_at === null;

    return (
        <button
            type="button"
            onClick={onSelect}
            className={`focus-premium group flex w-full items-start gap-3 text-left transition-colors ${compact ? "px-3 py-3" : "rounded-xl border p-4 sm:p-5"} ${unread ? "bg-accent/35" : "bg-card hover:bg-muted/60"}`}
            aria-label={`${notification.title}${unread ? ", chưa đọc" : ""}`}
        >
            <span
                className={`mt-0.5 grid shrink-0 place-items-center rounded-full ${compact ? "size-8" : "size-10"} ${unread ? "bg-primary text-primary-foreground" : "bg-muted text-primary"}`}
                aria-hidden="true"
            >
                <Icon size={compact ? 15 : 17} />
            </span>
            <span className="min-w-0 flex-1">
                <span className="flex items-start justify-between gap-3">
                    <span
                        className={`line-clamp-2 text-sm leading-5 ${unread ? "font-semibold text-primary" : "font-medium text-foreground"}`}
                    >
                        {notification.title}
                    </span>
                    {unread && (
                        <span
                            className="mt-1 size-2 shrink-0 rounded-full bg-secondary"
                            aria-label="Chưa đọc"
                        />
                    )}
                </span>
                <span
                    className={`mt-1 block text-xs leading-5 text-muted-foreground ${compact ? "line-clamp-2" : "max-w-3xl"}`}
                >
                    {notification.message}
                </span>
                <time
                    dateTime={notification.created_at}
                    className="mt-2 block text-[11px] font-medium text-secondary"
                >
                    {formatNotificationTime(notification.created_at)}
                </time>
            </span>
        </button>
    );
}
