import { useState, type ReactNode } from "react";
import { Link, useRouterState } from "@tanstack/react-router";
import {
    Bell,
    CalendarDays,
    ClipboardList,
    LayoutDashboard,
    LogOut,
    Menu,
    Star,
    Users,
    X,
} from "lucide-react";
import { Container } from "@/components/common/Container";
import { useAuth } from "@/contexts/AuthContext";

type StaffLink = { label: string; to: string; icon: typeof LayoutDashboard };

const receptionistLinks: StaffLink[] = [
    { label: "Tổng quan", to: "/receptionist", icon: LayoutDashboard },
    { label: "Lịch hôm nay", to: "/receptionist/today", icon: CalendarDays },
    { label: "Lịch hẹn", to: "/receptionist/appointments", icon: ClipboardList },
    { label: "Khách hàng", to: "/receptionist/customers", icon: Users },
    { label: "Thông báo", to: "/receptionist/notifications", icon: Bell },
];

const doctorLinks: StaffLink[] = [
    { label: "Tổng quan", to: "/doctor", icon: LayoutDashboard },
    { label: "Lịch hôm nay", to: "/doctor/today", icon: CalendarDays },
    { label: "Lịch hẹn của tôi", to: "/doctor/appointments", icon: ClipboardList },
    { label: "Đánh giá về tôi", to: "/doctor/reviews", icon: Star },
    { label: "Thông báo", to: "/doctor/notifications", icon: Bell },
];

export function StaffShell({ children, onLogout }: { children: ReactNode; onLogout: () => void }) {
    const { user } = useAuth();
    const path = useRouterState({ select: (state) => state.location.pathname });
    const [open, setOpen] = useState(false);
    const isDoctor = user?.role === "doctor";
    const links = isDoctor ? doctorLinks : receptionistLinks;
    const roleLabel = isDoctor ? "Bác sĩ" : "Lễ tân";

    return (
        <Container className="py-6 lg:grid lg:grid-cols-[220px_minmax(0,1fr)] lg:gap-8 lg:py-8">
            <div className="mb-5 lg:hidden">
                <button
                    type="button"
                    onClick={() => setOpen((value) => !value)}
                    aria-expanded={open}
                    aria-controls="staff-navigation"
                    className="focus-premium flex w-full items-center justify-between rounded-lg border bg-card p-4 font-semibold text-primary shadow-card"
                >
                    <span className="flex items-center gap-2">
                        <ClipboardList size={18} />
                        Khu vực {roleLabel.toLowerCase()}
                    </span>
                    {open ? <X size={18} /> : <Menu size={18} />}
                </button>
            </div>
            <aside
                id="staff-navigation"
                className={`${open ? "block" : "hidden"} rounded-md bg-navy-deep p-4 text-primary-foreground lg:block`}
            >
                <p className="label-luxury px-3 py-2">{roleLabel}</p>
                <p className="truncate px-3 pb-3 text-xs text-primary-foreground/65">
                    {user?.name}
                </p>
                <nav
                    className="mt-2 grid gap-1"
                    aria-label={`Điều hướng ${roleLabel.toLowerCase()}`}
                >
                    {links.map(({ label, to, icon: Icon }) => {
                        const exact = to === `/${isDoctor ? "doctor" : "receptionist"}`;
                        return (
                            <Link
                                key={to}
                                to={to}
                                activeOptions={{ exact }}
                                onClick={() => setOpen(false)}
                                activeProps={{ className: "bg-card text-primary" }}
                                className="focus-premium flex items-center gap-3 rounded-md px-3 py-3 text-sm font-medium transition-colors hover:bg-primary-foreground/10"
                            >
                                <Icon size={16} />
                                <span>{label}</span>
                            </Link>
                        );
                    })}
                    <button
                        type="button"
                        onClick={onLogout}
                        className="focus-premium mt-4 flex items-center gap-2 border-t border-primary-foreground/15 px-3 py-4 text-left text-sm text-primary-foreground/75 transition-colors hover:text-primary-foreground"
                    >
                        <LogOut size={16} />
                        Đăng xuất
                    </button>
                </nav>
            </aside>
            <div className="min-w-0 pb-10">
                <div className="mb-6 flex items-center justify-between border-b pb-4">
                    <div>
                        <p className="label-luxury">Khu vực vận hành</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {isDoctor
                                ? "Theo dõi và thực hiện lịch khám"
                                : "Điều phối lịch hẹn tại phòng khám"}
                        </p>
                    </div>
                    <span className="hidden rounded-full bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground sm:inline-flex">
                        {roleLabel}
                    </span>
                </div>
                {children}
            </div>
        </Container>
    );
}
