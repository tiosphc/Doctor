import { ArrowRight, CalendarClock } from "lucide-react";
import { Link } from "@tanstack/react-router";
import { StatusBadge } from "@/components/common/Status";
import type { AdminDashboardResponse } from "@/services/adminApi";

export function TodayAppointments({
    appointments,
}: {
    appointments: AdminDashboardResponse["today_appointments"];
}) {
    return (
        <section className="card-surface mt-6 overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-5 sm:px-6">
                <div>
                    <p className="label-luxury">Điều phối</p>
                    <h2 className="mt-1 text-2xl text-primary">Lịch hẹn hôm nay</h2>
                </div>
                <Link
                    to="/admin/appointments"
                    className="focus-premium inline-flex items-center gap-1 text-sm font-semibold text-primary hover:text-secondary-foreground"
                >
                    Xem tất cả lịch hẹn <ArrowRight size={16} aria-hidden="true" />
                </Link>
            </div>

            {appointments.length === 0 ? (
                <div className="grid min-h-44 place-items-center px-5 py-10 text-center">
                    <div>
                        <CalendarClock className="mx-auto text-secondary" aria-hidden="true" />
                        <p className="mt-3 text-sm text-muted-foreground">
                            Chưa có lịch hẹn nào trong hôm nay.
                        </p>
                    </div>
                </div>
            ) : (
                <>
                    <div className="hidden overflow-x-auto md:block">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/45 text-xs uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th className="px-6 py-3 font-medium">Giờ</th>
                                    <th className="px-4 py-3 font-medium">Khách hàng</th>
                                    <th className="px-4 py-3 font-medium">Bác sĩ</th>
                                    <th className="px-4 py-3 font-medium">Dịch vụ</th>
                                    <th className="px-6 py-3 text-right font-medium">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {appointments.map((appointment) => (
                                    <tr
                                        key={appointment.id}
                                        className="transition-colors hover:bg-muted/25"
                                    >
                                        <td className="px-6 py-4 font-semibold text-primary">
                                            {shortTime(appointment.start_time)}
                                        </td>
                                        <td className="px-4 py-4">
                                            <Link
                                                to="/admin/appointments/$id"
                                                params={{ id: String(appointment.id) }}
                                                className="font-semibold text-primary hover:underline"
                                            >
                                                {appointment.customer_name || "Khách vãng lai"}
                                            </Link>
                                            {appointment.booking_code && (
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {appointment.booking_code}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-4 text-muted-foreground">
                                            {appointment.doctor_name}
                                        </td>
                                        <td className="px-4 py-4 text-muted-foreground">
                                            {appointment.service_name}
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <StatusBadge status={appointment.status} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="divide-y md:hidden">
                        {appointments.map((appointment) => (
                            <Link
                                key={appointment.id}
                                to="/admin/appointments/$id"
                                params={{ id: String(appointment.id) }}
                                className="block p-5 transition-colors hover:bg-muted/25"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-lg font-semibold text-primary">
                                            {shortTime(appointment.start_time)} ·{" "}
                                            {appointment.customer_name || "Khách vãng lai"}
                                        </p>
                                        <p className="mt-1 truncate text-sm text-muted-foreground">
                                            {appointment.doctor_name} · {appointment.service_name}
                                        </p>
                                    </div>
                                    <StatusBadge status={appointment.status} />
                                </div>
                            </Link>
                        ))}
                    </div>
                </>
            )}
        </section>
    );
}

function shortTime(value: string): string {
    return value.slice(0, 5);
}
