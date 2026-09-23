import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { DashboardAnalytics } from "@/components/admin/dashboard/DashboardAnalytics";
import { DashboardOverview } from "@/components/admin/dashboard/DashboardOverview";
import { DashboardWidgets } from "@/components/admin/dashboard/DashboardWidgets";
import { TodayAppointments } from "@/components/admin/dashboard/TodayAppointments";
import { ErrorState } from "@/components/common/AsyncState";
import { AdminGuard, AdminTitle } from "@/pages/admin/AdminPages";
import {
    adminApi,
    type AdminDashboardPeriod,
    type AdminDashboardResponse,
} from "@/services/adminApi";

export function AdminDashboardPage() {
    const [period, setPeriod] = useState<AdminDashboardPeriod>("7_days");
    const query = useQuery({
        queryKey: ["admin-dashboard", period],
        queryFn: () => adminApi.dashboard(period),
        refetchInterval: 60_000,
    });

    return (
        <AdminGuard>
            <AdminTitle
                title="Tổng quan hệ thống"
                description="Theo dõi tình hình hoạt động, lịch hẹn và các thông tin cần xử lý."
            />
            {query.isPending ? (
                <DashboardSkeleton />
            ) : query.isError ? (
                <div className="mt-7">
                    <ErrorState
                        message="Không thể tải dữ liệu tổng quan. Vui lòng thử lại."
                        retry={() => query.refetch()}
                    />
                </div>
            ) : (
                <DashboardContent data={query.data} period={period} onPeriodChange={setPeriod} />
            )}
        </AdminGuard>
    );
}

function DashboardContent({
    data,
    period,
    onPeriodChange,
}: {
    data: AdminDashboardResponse;
    period: AdminDashboardPeriod;
    onPeriodChange: (period: AdminDashboardPeriod) => void;
}) {
    return (
        <>
            <DashboardOverview overview={data.overview} today={data.today} />
            <TodayAppointments appointments={data.today_appointments} />
            <DashboardAnalytics
                statistics={data.appointment_statistics}
                actions={data.actions_required}
                period={period}
                onPeriodChange={onPeriodChange}
            />
            <DashboardWidgets
                activities={data.recent_activities}
                doctors={data.doctor_today}
                services={data.popular_services}
            />
        </>
    );
}

function DashboardSkeleton() {
    return (
        <div className="mt-7 animate-pulse space-y-6" aria-label="Đang tải dữ liệu tổng quan">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {Array.from({ length: 4 }, (_, index) => (
                    <div key={index} className="card-surface h-36 bg-muted/45" />
                ))}
            </div>
            <div className="card-surface h-44 bg-muted/45" />
            <div className="card-surface h-80 bg-muted/45" />
            <div className="grid gap-6 lg:grid-cols-2">
                <div className="card-surface h-80 bg-muted/45" />
                <div className="card-surface h-80 bg-muted/45" />
            </div>
        </div>
    );
}
