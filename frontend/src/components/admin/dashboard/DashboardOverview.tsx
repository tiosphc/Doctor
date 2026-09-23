import { Link } from "@tanstack/react-router";
import {
    CalendarCheck2,
    CalendarDays,
    CircleX,
    Clock3,
    Stethoscope,
    Sparkles,
    Users,
    type LucideProps,
} from "lucide-react";
import type { ComponentType } from "react";
import type { AdminDashboardResponse } from "@/services/adminApi";

export function DashboardOverview({
    overview,
    today,
}: Pick<AdminDashboardResponse, "overview" | "today">) {
    const overviewItems = [
        { label: "Bác sĩ", value: overview.doctors, to: "/admin/doctors", icon: Stethoscope },
        { label: "Dịch vụ", value: overview.services, to: "/admin/services", icon: Sparkles },
        {
            label: "Lịch hẹn",
            value: overview.appointments,
            to: "/admin/appointments",
            icon: CalendarDays,
        },
        { label: "Khách hàng", value: overview.customers, to: "/admin/customers", icon: Users },
    ] as const;
    const todayItems = [
        { label: "Lịch hôm nay", value: today.appointments, icon: CalendarDays },
        { label: "Chờ check-in", value: today.waiting_checkin, icon: Clock3 },
        { label: "Đang khám", value: today.in_progress, icon: Stethoscope },
        { label: "Hoàn thành", value: today.completed, icon: CalendarCheck2 },
        { label: "Đã hủy", value: today.cancelled, icon: CircleX },
    ];

    return (
        <>
            <div className="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {overviewItems.map((item) => (
                    <OverviewCard key={item.label} {...item} />
                ))}
            </div>

            <section className="card-surface mt-6 p-5 sm:p-6">
                <div className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <p className="label-luxury">Vận hành trong ngày</p>
                        <h2 className="mt-1 text-2xl text-primary">Tình hình hôm nay</h2>
                    </div>
                    <p className="text-sm text-muted-foreground">{formatDate(today.date)}</p>
                </div>
                <div className="mt-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                    {todayItems.map(({ label, value, icon: Icon }) => (
                        <div key={label} className="rounded-lg border bg-muted/35 p-4">
                            <div className="flex items-center justify-between gap-3">
                                <strong className="text-2xl text-primary">{value}</strong>
                                <Icon className="text-secondary" size={19} aria-hidden="true" />
                            </div>
                            <p className="mt-2 text-xs leading-5 text-muted-foreground">{label}</p>
                        </div>
                    ))}
                </div>
            </section>
        </>
    );
}

function OverviewCard({
    label,
    value,
    to,
    icon: Icon,
}: {
    label: string;
    value: number;
    to: string;
    icon: ComponentType<LucideProps>;
}) {
    return (
        <Link
            to={to}
            className="card-surface group p-5 transition duration-200 hover:-translate-y-0.5 hover:border-secondary/60 hover:shadow-card"
        >
            <span className="grid size-10 place-items-center rounded-full bg-muted text-secondary transition-colors group-hover:bg-secondary/15">
                <Icon size={20} aria-hidden="true" />
            </span>
            <strong className="mt-4 block text-4xl text-primary">{value}</strong>
            <span className="mt-1 block text-sm text-muted-foreground">{label}</span>
        </Link>
    );
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat("vi-VN", {
        weekday: "long",
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
    }).format(new Date(`${value}T00:00:00`));
}
