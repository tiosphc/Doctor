import { Link } from "@tanstack/react-router";
import { Activity, CalendarDays, Stethoscope } from "lucide-react";
import type { AdminDashboardResponse } from "@/services/adminApi";

export function DashboardWidgets({
    activities,
    doctors,
    services,
}: {
    activities: AdminDashboardResponse["recent_activities"];
    doctors: AdminDashboardResponse["doctor_today"];
    services: AdminDashboardResponse["popular_services"];
}) {
    const maximumServiceCount = Math.max(...services.map((service) => service.appointments), 1);

    return (
        <>
            <div className="mt-6 grid gap-6 lg:grid-cols-2">
                <section className="card-surface p-5 sm:p-6">
                    <p className="label-luxury">Nhật ký hệ thống</p>
                    <h2 className="mt-1 text-2xl text-primary">Hoạt động gần đây</h2>
                    {!activities.available ? (
                        <div className="mt-6 rounded-lg border border-dashed bg-muted/25 p-6 text-center">
                            <Activity className="mx-auto text-secondary" aria-hidden="true" />
                            <p className="mt-3 text-sm text-muted-foreground">
                                Hệ thống chưa có Audit Log nên chưa thể hiển thị hoạt động gần đây.
                            </p>
                        </div>
                    ) : activities.items.length === 0 ? (
                        <p className="mt-6 rounded-lg border bg-muted/25 p-6 text-center text-sm text-muted-foreground">
                            Chưa có hoạt động nào được ghi nhận.
                        </p>
                    ) : (
                        <>
                            <ul className="mt-5 divide-y">
                                {activities.items.map((activity) => (
                                    <li key={activity.id} className="py-3 text-sm">
                                        <p className="font-semibold text-primary">
                                            {activity.actor}
                                        </p>
                                        <p className="mt-1 text-muted-foreground">
                                            {activity.action} · {activity.subject}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                            <Link
                                to="/admin/audit-logs"
                                className="mt-4 inline-flex text-sm font-semibold text-secondary hover:underline"
                            >
                                Xem nhật ký hoạt động →
                            </Link>
                        </>
                    )}
                </section>

                <section className="card-surface p-5 sm:p-6">
                    <p className="label-luxury">Phân bổ trong ngày</p>
                    <h2 className="mt-1 text-2xl text-primary">Bác sĩ hôm nay</h2>
                    {doctors.length === 0 ? (
                        <div className="mt-6 rounded-lg border border-dashed bg-muted/25 p-6 text-center">
                            <Stethoscope className="mx-auto text-secondary" aria-hidden="true" />
                            <p className="mt-3 text-sm text-muted-foreground">
                                Chưa có bác sĩ nào có lịch trong hôm nay.
                            </p>
                        </div>
                    ) : (
                        <ul className="mt-5 divide-y">
                            {doctors.map((doctor) => (
                                <li key={doctor.id} className="flex items-center gap-4 py-3">
                                    <span className="grid size-10 shrink-0 place-items-center rounded-full bg-muted text-primary">
                                        <Stethoscope size={18} aria-hidden="true" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-semibold text-primary">
                                            {doctor.name}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {doctor.completed} hoàn thành · {doctor.in_progress}{" "}
                                            đang khám
                                        </p>
                                    </div>
                                    <strong className="text-sm text-primary">
                                        {doctor.appointments} lịch
                                    </strong>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <section className="card-surface mt-6 p-5 sm:p-6">
                <p className="label-luxury">Tháng hiện tại</p>
                <h2 className="mt-1 text-2xl text-primary">Dịch vụ được đặt nhiều</h2>
                {services.length === 0 ? (
                    <div className="mt-6 rounded-lg border border-dashed bg-muted/25 p-6 text-center">
                        <CalendarDays className="mx-auto text-secondary" aria-hidden="true" />
                        <p className="mt-3 text-sm text-muted-foreground">
                            Chưa có lượt đặt dịch vụ nào trong tháng này.
                        </p>
                    </div>
                ) : (
                    <div className="mt-5 grid gap-x-8 gap-y-5 md:grid-cols-2">
                        {services.map((service) => (
                            <div key={service.id}>
                                <div className="flex items-center justify-between gap-4 text-sm">
                                    <span className="truncate font-semibold text-primary">
                                        {service.name}
                                    </span>
                                    <span className="shrink-0 text-muted-foreground">
                                        {service.appointments} lượt
                                    </span>
                                </div>
                                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full bg-secondary"
                                        style={{
                                            width: `${Math.max(8, (service.appointments / maximumServiceCount) * 100)}%`,
                                        }}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </section>
        </>
    );
}
