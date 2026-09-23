import type { AppointmentStatus } from "@/types";

const labels: Record<AppointmentStatus, string> = {
    pending: "Chờ xác nhận",
    confirmed: "Đã xác nhận",
    checked_in: "Đã check-in",
    in_progress: "Đang khám",
    treatment_done: "Bác sĩ đã hoàn thành",
    completed: "Hoàn thành",
    cancelled: "Đã hủy",
    no_show: "Không đến",
};

export function Badge({
    children,
    tone = "default",
}: {
    children: React.ReactNode;
    tone?: "default" | "success" | "warning" | "info" | "danger" | "primary";
}) {
    return (
        <span
            className={`inline-flex shrink-0 items-center whitespace-nowrap rounded-full border px-3 py-1 text-xs font-semibold leading-5 ${tone === "success" ? "border-emerald-200 bg-emerald-50 text-emerald-700" : tone === "warning" ? "border-amber-200 bg-amber-50 text-amber-800" : tone === "info" ? "border-indigo-200 bg-indigo-50 text-indigo-700" : tone === "danger" ? "border-red-200 bg-red-50 text-red-700" : tone === "primary" ? "border-primary bg-primary text-primary-foreground" : "border-border bg-muted text-primary"}`}
        >
            {children}
        </span>
    );
}

export function StatusBadge({ status }: { status: AppointmentStatus }) {
    return (
        <Badge
            tone={
                status === "confirmed"
                    ? "success"
                    : status === "checked_in"
                      ? "primary"
                      : status === "in_progress"
                        ? "info"
                        : status === "treatment_done"
                          ? "success"
                          : status === "completed"
                            ? "default"
                            : status === "pending"
                              ? "warning"
                              : "danger"
            }
        >
            {labels[status]}
        </Badge>
    );
}

const progressStages: Array<{ status: AppointmentStatus; label: string }> = [
    { status: "pending", label: "Chờ xác nhận" },
    { status: "confirmed", label: "Đã xác nhận" },
    { status: "checked_in", label: "Đã check-in" },
    { status: "in_progress", label: "Đang khám" },
    { status: "treatment_done", label: "Bác sĩ đã hoàn thành" },
    { status: "completed", label: "Hoàn tất" },
];

export function AppointmentProgress({ status }: { status: AppointmentStatus }) {
    const currentIndex = progressStages.findIndex((stage) => stage.status === status);

    if (currentIndex === -1) {
        return <p className="mt-5 text-sm font-semibold text-red-700">{labels[status]}</p>;
    }

    return (
        <ol
            className="mt-6 grid gap-2 border-y py-4 sm:grid-cols-6"
            aria-label="Tiến trình lịch hẹn"
        >
            {progressStages.map((stage, index) => {
                const complete = index <= currentIndex;
                const active = index === currentIndex;

                return (
                    <li key={stage.status} className="flex min-w-0 items-center gap-2 sm:block">
                        <span
                            className={`grid size-7 shrink-0 place-items-center rounded-full border text-xs font-semibold ${complete ? "border-primary bg-primary text-primary-foreground" : "border-border bg-card text-muted-foreground"}`}
                            aria-current={active ? "step" : undefined}
                        >
                            {index + 1}
                        </span>
                        <span
                            className={`text-xs leading-4 ${active ? "font-semibold text-primary" : "text-muted-foreground"}`}
                        >
                            {stage.label}
                        </span>
                        {index < progressStages.length - 1 && (
                            <span
                                className={`hidden h-px sm:mt-3 sm:block ${index < currentIndex ? "bg-primary" : "bg-border"}`}
                            />
                        )}
                    </li>
                );
            })}
        </ol>
    );
}

export const statusLabel = (status: AppointmentStatus) => labels[status];
