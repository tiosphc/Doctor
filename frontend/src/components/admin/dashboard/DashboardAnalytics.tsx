import { ArrowRight, CheckCircle2, CircleAlert } from "lucide-react";
import { Link } from "@tanstack/react-router";
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from "recharts";
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from "@/components/ui/chart";
import type { AdminDashboardPeriod, AdminDashboardResponse } from "@/services/adminApi";

const chartConfig = {
    total: { label: "Tổng lịch", color: "var(--primary)" },
    completed: { label: "Hoàn thành", color: "oklch(0.62 0.12 155)" },
    cancelled: { label: "Đã hủy", color: "oklch(0.7 0.12 25)" },
} satisfies ChartConfig;

const periods: Array<{ value: AdminDashboardPeriod; label: string }> = [
    { value: "7_days", label: "7 ngày" },
    { value: "30_days", label: "30 ngày" },
    { value: "month", label: "Tháng này" },
];

export function DashboardAnalytics({
    statistics,
    actions,
    period,
    onPeriodChange,
}: {
    statistics: AdminDashboardResponse["appointment_statistics"];
    actions: AdminDashboardResponse["actions_required"];
    period: AdminDashboardPeriod;
    onPeriodChange: (period: AdminDashboardPeriod) => void;
}) {
    const chartData = statistics.items.map((item) => ({
        ...item,
        label: formatShortDate(item.date),
    }));

    return (
        <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(280px,0.75fr)]">
            <section className="card-surface min-w-0 p-5 sm:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div>
                        <p className="label-luxury">Xu hướng vận hành</p>
                        <h2 className="mt-1 text-2xl text-primary">Thống kê lịch hẹn</h2>
                    </div>
                    <div className="flex flex-wrap gap-2" aria-label="Khoảng thời gian thống kê">
                        {periods.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => onPeriodChange(option.value)}
                                className={`focus-premium rounded-full border px-3 py-2 text-xs font-semibold transition-colors ${period === option.value ? "border-primary bg-primary text-primary-foreground" : "bg-card text-muted-foreground hover:border-secondary hover:text-primary"}`}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                </div>
                <ChartContainer config={chartConfig} className="mt-6 h-72 w-full aspect-auto">
                    <BarChart data={chartData} accessibilityLayer margin={{ left: -18, right: 4 }}>
                        <CartesianGrid vertical={false} />
                        <XAxis dataKey="label" tickLine={false} axisLine={false} minTickGap={18} />
                        <YAxis allowDecimals={false} tickLine={false} axisLine={false} width={36} />
                        <ChartTooltip
                            cursor={false}
                            content={
                                <ChartTooltipContent labelFormatter={(label) => String(label)} />
                            }
                        />
                        <ChartLegend content={<ChartLegendContent />} />
                        <Bar dataKey="total" fill="var(--color-total)" radius={[4, 4, 0, 0]} />
                        <Bar
                            dataKey="completed"
                            fill="var(--color-completed)"
                            radius={[4, 4, 0, 0]}
                        />
                        <Bar
                            dataKey="cancelled"
                            fill="var(--color-cancelled)"
                            radius={[4, 4, 0, 0]}
                        />
                    </BarChart>
                </ChartContainer>
            </section>

            <section className="card-surface p-5 sm:p-6">
                <p className="label-luxury">Ưu tiên vận hành</p>
                <h2 className="mt-1 text-2xl text-primary">Cần xử lý</h2>
                {actions.length === 0 ? (
                    <div className="mt-8 rounded-lg border border-emerald-200 bg-emerald-50 p-5 text-center text-emerald-800">
                        <CheckCircle2 className="mx-auto" aria-hidden="true" />
                        <p className="mt-3 text-sm">Hiện không có vấn đề nào cần xử lý.</p>
                    </div>
                ) : (
                    <ul className="mt-5 grid gap-3">
                        {actions.map((action) => (
                            <li key={action.key}>
                                <Link
                                    to={action.url}
                                    className="group flex items-center gap-3 rounded-lg border bg-muted/25 p-3 transition-colors hover:border-secondary/60 hover:bg-muted/50"
                                >
                                    <span className="grid size-9 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-800">
                                        <CircleAlert size={17} aria-hidden="true" />
                                    </span>
                                    <span className="min-w-0 flex-1 text-sm text-muted-foreground">
                                        <strong className="font-semibold text-primary">
                                            {action.count}
                                        </strong>{" "}
                                        {action.label}
                                    </span>
                                    <ArrowRight
                                        className="text-muted-foreground transition-transform group-hover:translate-x-0.5"
                                        size={16}
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}

function formatShortDate(value: string): string {
    const [year = "", month = "", day = ""] = value.split("-");
    return `${day}/${month}/${year.slice(2)}`;
}
