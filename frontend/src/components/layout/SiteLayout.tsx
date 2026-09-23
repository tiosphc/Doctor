import { useEffect, useState } from "react";
import { Link, useNavigate, useRouterState } from "@tanstack/react-router";
import {
    Menu,
    X,
    Instagram,
    Facebook,
    MapPin,
    Phone,
    Mail,
    Clock,
    LogOut,
    ChevronDown,
    CalendarDays,
    Grid2X2,
    CreditCard,
    CircleUserRound,
    Bell,
    Gift,
    CalendarSearch,
} from "lucide-react";
import { ButtonLink } from "@/components/common/Button";
import { Container } from "@/components/common/Container";
import { useAuth } from "@/contexts/AuthContext";
import { MobileServiceMenu, ServiceMegaMenu } from "@/components/services/ServiceNavigation";
import { NotificationBell } from "@/components/notifications/NotificationBell";
import { useNotificationUnreadCount } from "@/hooks/useNotifications";
import { StaffShell } from "@/components/layout/StaffLayout";

const nav = [
    ["Trang chủ", "/"],
    ["Dịch vụ", "/services"],
    ["Bác sĩ", "/doctors"],
    ["Kiến thức", "/blogs"],
] as const;

function initials(name: string): string {
    return name
        .trim()
        .split(/\s+/)
        .slice(-2)
        .map((part) => part[0])
        .join("")
        .toUpperCase();
}

export function SiteLayout({ children }: { children: React.ReactNode }) {
    const [open, setOpen] = useState(false);
    const [headerVisible, setHeaderVisible] = useState(true);
    const [isScrolled, setIsScrolled] = useState(false);
    const path = useRouterState({ select: (state) => state.location.pathname });
    const { user, isLoading, logout } = useAuth();
    const navigate = useNavigate();
    const account = path.startsWith("/account");
    const admin = path.startsWith("/admin");
    const staff =
        path === "/receptionist" ||
        path.startsWith("/receptionist/") ||
        path === "/doctor" ||
        path.startsWith("/doctor/");
    const isServiceDetail = /^\/services\/[^/]+\/[^/]+\/?$/.test(path);
    const hasHeroHeader = path === "/" || (path.startsWith("/services") && !isServiceDetail);
    const transparentHeader = hasHeroHeader && !isScrolled;
    const canLookupAppointments = !isLoading && (!user || user.role === "customer");

    useEffect(() => {
        let previousScrollY = window.scrollY;

        function handleScroll(): void {
            const currentScrollY = window.scrollY;
            const delta = currentScrollY - previousScrollY;

            setIsScrolled(currentScrollY > 24);

            if (currentScrollY <= 24) {
                setHeaderVisible(true);
            } else if (delta > 2) {
                setHeaderVisible(false);
            } else if (delta < -2) {
                setHeaderVisible(true);
            }

            previousScrollY = currentScrollY;
        }

        window.addEventListener("scroll", handleScroll, { passive: true });

        return () => window.removeEventListener("scroll", handleScroll);
    }, []);

    useEffect(() => {
        if (open) setHeaderVisible(true);
    }, [open]);

    async function signOut() {
        await logout();
        await navigate({ to: "/" });
    }

    return (
        <div className="min-h-screen">
            <header
                className={`fixed inset-x-0 top-0 z-50 transition-[transform,background-color,box-shadow] duration-300 motion-reduce:transition-none ${headerVisible ? "translate-y-0" : "-translate-y-full"} ${transparentHeader ? "bg-transparent text-white" : "bg-background/90 text-foreground shadow-sm backdrop-blur-xl"}`}
            >
                <Container className="flex h-16 items-center justify-between gap-5 md:h-20">
                    <Link
                        to="/"
                        className={`shrink-0 transition-colors ${transparentHeader ? "text-white" : "text-primary"}`}
                        aria-label="Junie - Trang chủ"
                    >
                        <span className="font-display text-xl font-semibold tracking-[.2em] md:text-[1.25rem]">
                            JUNIE
                        </span>
                        <span
                            className={`hidden text-[8px] tracking-[.24em] md:block ${transparentHeader ? "text-white/70" : "text-secondary"}`}
                        >
                            AESTHETIC & DERMATOLOGY
                        </span>
                    </Link>
                    <nav
                        className="hidden items-center justify-center gap-8 lg:flex"
                        aria-label="Điều hướng chính"
                    >
                        {nav.map(([name, to]) =>
                            to === "/services" ? (
                                <ServiceMegaMenu key={name} inverted={transparentHeader} />
                            ) : (
                                <Link
                                    key={name}
                                    to={to}
                                    activeOptions={{ exact: to === "/" }}
                                    className={`focus-premium border-b-2 border-transparent py-2 text-sm transition-colors ${transparentHeader ? "text-white/80 hover:border-white/60 hover:text-white" : "text-foreground/80 hover:text-primary"}`}
                                    activeProps={{
                                        className: transparentHeader
                                            ? "border-white font-semibold text-white"
                                            : "border-primary font-semibold text-primary",
                                    }}
                                >
                                    {name}
                                </Link>
                            ),
                        )}
                        <a
                            href="/#about"
                            className={`focus-premium border-b-2 border-transparent py-2 text-sm transition-colors ${transparentHeader ? "text-white/80 hover:border-white/60 hover:text-white" : "text-foreground/80 hover:text-primary"}`}
                        >
                            Về chúng tôi
                        </a>
                    </nav>
                    <div className="flex items-center justify-end gap-2 md:gap-4">
                        {!isLoading &&
                            (user ? (
                                <Link
                                    to={
                                        user.role === "admin"
                                            ? "/admin"
                                            : user.role === "receptionist"
                                              ? "/receptionist"
                                              : user.role === "doctor"
                                                ? "/doctor"
                                                : "/account"
                                    }
                                    className={`focus-premium hidden items-center gap-3 border-l pl-5 md:flex ${transparentHeader ? "border-white/25 text-white" : "border-border text-primary"}`}
                                    aria-label={`Mở ${user.role === "admin" ? "khu vực quản trị" : "tài khoản"}`}
                                >
                                    <span className="grid size-9 place-items-center rounded-full bg-primary text-xs font-semibold text-primary-foreground">
                                        {initials(user.name)}
                                    </span>
                                    <span className="grid gap-0.5 text-left">
                                        <span className="max-w-28 truncate text-xs font-semibold">
                                            {user.name}
                                        </span>
                                        <span
                                            className={`text-[10px] ${transparentHeader ? "text-white/65" : "text-secondary"}`}
                                        >
                                            {user.role === "admin"
                                                ? "Quản trị viên"
                                                : user.role === "receptionist"
                                                  ? "Lễ tân"
                                                  : user.role === "doctor"
                                                    ? "Bác sĩ"
                                                    : "Khách hàng"}
                                        </span>
                                    </span>
                                </Link>
                            ) : (
                                <Link
                                    to="/login"
                                    className={`focus-premium hidden text-sm font-medium md:block ${transparentHeader ? "text-white" : "text-primary"}`}
                                >
                                    Đăng nhập
                                </Link>
                            ))}
                        {!isLoading && user && <NotificationBell inverted={transparentHeader} />}
                        {canLookupAppointments && (
                            <ButtonLink
                                to="/appointment-lookup"
                                variant="outline"
                                aria-label="ỏ"
                                className={`hidden min-h-9 shrink-0 rounded-full px-2.5 text-xs lg:inline-flex xl:px-3 ${transparentHeader ? "border-white/60 text-white hover:bg-white/10" : ""}`}
                            >
                                <CalendarSearch size={14} />
                                <span className="hidden xl:inline">Tra cứu</span>
                            </ButtonLink>
                        )}
                        {!isLoading && user?.role !== "admin" && user?.role !== "doctor" && (
                            <ButtonLink
                                to="/booking"
                                className="hidden min-h-9 rounded-full px-5 text-xs uppercase tracking-[.08em] sm:inline-flex"
                            >
                                <CalendarDays size={14} />
                                Đặt lịch
                            </ButtonLink>
                        )}
                        <button
                            aria-label="Mở menu điều hướng"
                            aria-expanded={open}
                            aria-controls="mobile-navigation"
                            onClick={() => setOpen(true)}
                            className={`focus-premium grid size-10 place-items-center rounded-full lg:hidden ${transparentHeader ? "text-white" : "text-primary"}`}
                        >
                            <Menu />
                        </button>
                    </div>
                </Container>
            </header>
            {open && (
                <div
                    className="fixed inset-0 z-[60] bg-navy-deep/45 lg:hidden"
                    onClick={() => setOpen(false)}
                >
                    <aside
                        id="mobile-navigation"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Menu điều hướng"
                        className="ml-auto flex h-full w-[86%] max-w-sm flex-col bg-background p-6 shadow-2xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className="flex items-center justify-between border-b pb-5">
                            <span className="font-display text-xl tracking-[.18em] text-primary">
                                JUNIE
                            </span>
                            <button
                                aria-label="Đóng menu"
                                onClick={() => setOpen(false)}
                                className="focus-premium grid size-10 place-items-center rounded-full"
                            >
                                <X />
                            </button>
                        </div>
                        <nav className="grid gap-1 py-6" aria-label="Điều hướng di động">
                            {nav.map(([name, to]) =>
                                to === "/services" ? (
                                    <MobileServiceMenu
                                        key={name}
                                        onNavigate={() => setOpen(false)}
                                    />
                                ) : (
                                    <Link
                                        key={name}
                                        to={to}
                                        activeOptions={{ exact: to === "/" }}
                                        onClick={() => setOpen(false)}
                                        className="focus-premium border-b py-4 font-medium text-primary"
                                    >
                                        {name}
                                    </Link>
                                ),
                            )}
                            <a
                                href="/#about"
                                onClick={() => setOpen(false)}
                                className="focus-premium border-b py-4 font-medium text-primary"
                            >
                                Về chúng tôi
                            </a>
                            {canLookupAppointments && (
                                <Link
                                    to="/appointment-lookup"
                                    onClick={() => setOpen(false)}
                                    className="focus-premium flex items-center gap-2 border-b py-4 font-medium text-primary"
                                >
                                    <CalendarSearch size={18} />
                                    Tra cứu lịch hẹn
                                </Link>
                            )}
                            {user ? (
                                <>
                                    <Link
                                        to={
                                            user.role === "admin"
                                                ? "/admin"
                                                : user.role === "receptionist"
                                                  ? "/receptionist"
                                                  : user.role === "doctor"
                                                    ? "/doctor"
                                                    : "/account"
                                        }
                                        onClick={() => setOpen(false)}
                                        className="focus-premium border-b py-4 font-medium text-primary"
                                    >
                                        Tài khoản
                                    </Link>
                                    <button
                                        onClick={signOut}
                                        className="focus-premium py-4 text-left font-medium text-red-700"
                                    >
                                        Đăng xuất
                                    </button>
                                </>
                            ) : (
                                <Link
                                    to="/login"
                                    onClick={() => setOpen(false)}
                                    className="focus-premium border-b py-4 font-medium text-primary"
                                >
                                    Đăng nhập
                                </Link>
                            )}
                        </nav>
                        {!isLoading && user?.role !== "admin" && user?.role !== "doctor" && (
                            <ButtonLink to="/booking" className="mt-auto">
                                ĐẶT LỊCH
                            </ButtonLink>
                        )}
                    </aside>
                </div>
            )}
            <main className={hasHeroHeader ? "" : "pt-16 md:pt-20"}>
                {admin ? (
                    <AdminShell onLogout={signOut}>{children}</AdminShell>
                ) : staff ? (
                    <StaffShell onLogout={signOut}>{children}</StaffShell>
                ) : account ? (
                    <AccountShell onLogout={signOut}>{children}</AccountShell>
                ) : (
                    children
                )}
            </main>
            {account ? (
                <AccountFooter />
            ) : (
                !admin && <Footer showAppointmentLookup={canLookupAppointments} />
            )}
        </div>
    );
}

function AccountFooter() {
    return (
        <footer className="border-t bg-card py-5 text-xs text-muted-foreground">
            <Container className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p>
                    <span className="font-display tracking-[.12em] text-primary">JUNIE</span>
                    <span className="ml-2">— Viện Thẩm Mỹ & Da Liễu chuẩn y khoa.</span>
                </p>
                <div className="flex flex-wrap gap-x-6 gap-y-2">
                    <span>Chính sách bảo mật</span>
                    <Link to="/appointment-lookup" className="focus-premium hover:text-primary">
                        Tra cứu lịch hẹn
                    </Link>
                    <a href="tel:19006688" className="focus-premium hover:text-primary">
                        Hotline: 1900 6688
                    </a>
                </div>
            </Container>
        </footer>
    );
}

function AccountShell({ children, onLogout }: { children: React.ReactNode; onLogout: () => void }) {
    const [open, setOpen] = useState(false);
    const { user } = useAuth();
    const notificationUnread = useNotificationUnreadCount(
        user?.role === "customer" ? user.id : null,
    );
    const unreadCount = notificationUnread.data ?? 0;
    const links = [
        ["Tổng quan", "/account", Grid2X2],
        ["Lịch hẹn của tôi", "/account/appointments", CalendarDays],
        ["Thông báo", "/account/notifications", Bell],
        ["Ưu đãi của tôi", "/account/vouchers", Gift],
        ["Quyền lợi thành viên", "/account/loyalty", CreditCard],
        ["Thông tin cá nhân", "/account/profile", CircleUserRound],
    ] as const;
    return (
        <Container className="py-6 md:grid md:grid-cols-[180px_minmax(0,1fr)] md:gap-12 md:py-10 lg:grid-cols-[180px_minmax(0,1fr)]">
            <div className="mb-5 md:hidden">
                <button
                    onClick={() => setOpen(!open)}
                    aria-expanded={open}
                    aria-controls="account-navigation"
                    className="focus-premium flex w-full items-center justify-between rounded-lg border bg-card p-4 font-semibold text-primary shadow-card"
                >
                    <span className="flex items-center gap-2">
                        <CircleUserRound size={18} />
                        Tài khoản của tôi
                    </span>
                    <Menu size={18} />
                </button>
                {open && (
                    <nav
                        id="account-navigation"
                        className="mt-2 grid rounded-lg border bg-card p-2 shadow-card"
                        aria-label="Điều hướng tài khoản"
                    >
                        {links.map(([name, to, Icon]) => (
                            <Link
                                key={name}
                                to={to}
                                onClick={() => setOpen(false)}
                                className="focus-premium flex items-center gap-3 rounded-md p-3 text-sm text-primary"
                                activeOptions={{ exact: to === "/account" }}
                                activeProps={{ className: "bg-primary text-primary-foreground" }}
                            >
                                <Icon size={17} />
                                <span className="min-w-0 flex-1">{name}</span>
                                {to === "/account/notifications" && unreadCount > 0 && (
                                    <span className="rounded-full bg-secondary px-1.5 py-0.5 text-[10px] font-bold text-secondary-foreground">
                                        {unreadCount > 99 ? "99+" : unreadCount}
                                    </span>
                                )}
                            </Link>
                        ))}
                        <button
                            onClick={onLogout}
                            className="focus-premium mt-1 flex items-center gap-3 border-t px-3 py-4 text-left text-sm text-muted-foreground"
                        >
                            <LogOut size={17} />
                            Đăng xuất
                        </button>
                    </nav>
                )}
            </div>
            <aside className="hidden md:block">
                <p className="label-luxury mb-5">Tài khoản</p>
                <nav className="grid gap-1" aria-label="Điều hướng tài khoản">
                    {links.map(([name, to, Icon]) => (
                        <Link
                            key={name}
                            to={to}
                            activeOptions={{ exact: to === "/account" }}
                            activeProps={{
                                className: "bg-primary text-primary-foreground shadow-sm",
                            }}
                            className="focus-premium flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium text-primary transition-colors hover:bg-muted"
                        >
                            <Icon size={17} />
                            <span className="min-w-0 flex-1">{name}</span>
                            {to === "/account/notifications" && unreadCount > 0 && (
                                <span className="rounded-full bg-secondary px-1.5 py-0.5 text-[10px] font-bold text-secondary-foreground">
                                    {unreadCount > 99 ? "99+" : unreadCount}
                                </span>
                            )}
                        </Link>
                    ))}
                    <button
                        onClick={onLogout}
                        className="focus-premium mt-5 flex items-center gap-2 border-t px-3 py-5 text-left text-sm text-muted-foreground transition-colors hover:text-primary"
                    >
                        <LogOut size={16} />
                        Đăng xuất
                    </button>
                </nav>
                <div className="mt-5 rounded-xl border bg-card p-4 shadow-card">
                    <p className="label-luxury text-[10px]">Trợ lý khách hàng</p>
                    <p className="mt-2 text-xs leading-5 text-muted-foreground">
                        Cần hỗ trợ lịch hẹn? Đội ngũ Junie luôn sẵn sàng đồng hành.
                    </p>
                    <a
                        href="tel:19006688"
                        className="focus-premium mt-3 inline-flex text-xs font-semibold text-primary"
                    >
                        Hotline: 1900 6688
                    </a>
                </div>
            </aside>
            <div className="min-w-0 pb-10">{children}</div>
        </Container>
    );
}

type AdminRoute =
    | "/admin"
    | "/admin/appointments"
    | "/admin/customers"
    | "/admin/doctors"
    | "/admin/staff"
    | "/admin/services"
    | "/admin/service-categories"
    | "/admin/reviews"
    | "/admin/vouchers"
    | "/admin/voucher-management"
    | "/admin/notifications"
    | "/admin/blogs"
    | "/admin/blog-categories"
    | "/admin/audit-logs";

function AdminShell({ children, onLogout }: { children: React.ReactNode; onLogout: () => void }) {
    const path = useRouterState({ select: (state) => state.location.pathname });
    const isPersonnelRoute = path.startsWith("/admin/doctors") || path.startsWith("/admin/staff");
    const isServiceRoute =
        path.startsWith("/admin/services") || path.startsWith("/admin/service-categories");
    const isVoucherRoute =
        path.startsWith("/admin/vouchers") || path === "/admin/voucher-management";
    const isBlogRoute =
        path.startsWith("/admin/blogs") || path.startsWith("/admin/blog-categories");
    const [personnelExpanded, setPersonnelExpanded] = useState(isPersonnelRoute);
    const [servicesExpanded, setServicesExpanded] = useState(isServiceRoute);
    const [vouchersExpanded, setVouchersExpanded] = useState(isVoucherRoute);
    const [blogsExpanded, setBlogsExpanded] = useState(isBlogRoute);

    useEffect(() => {
        if (isPersonnelRoute) setPersonnelExpanded(true);
    }, [isPersonnelRoute]);
    useEffect(() => {
        if (isServiceRoute) setServicesExpanded(true);
    }, [isServiceRoute]);
    useEffect(() => {
        if (isVoucherRoute) setVouchersExpanded(true);
    }, [isVoucherRoute]);
    useEffect(() => {
        if (isBlogRoute) setBlogsExpanded(true);
    }, [isBlogRoute]);

    const primaryLinks: ReadonlyArray<readonly [string, AdminRoute]> = [
        ["Tổng quan", "/admin"],
        ["Lịch hẹn", "/admin/appointments"],
        ["Khách hàng", "/admin/customers"],
    ];

    return (
        <Container className="py-8 lg:grid lg:grid-cols-[240px_minmax(0,1fr)] lg:gap-8">
            <aside className="mb-6 rounded-md bg-navy-deep p-4 text-primary-foreground lg:mb-0">
                <p className="label-luxury px-3 py-2">Quản trị</p>
                <nav className="mt-2 grid gap-1" aria-label="Điều hướng quản trị">
                    {primaryLinks.map(([label, to]) => (
                        <AdminNavLink key={label} label={label} to={to} exact={to === "/admin"} />
                    ))}
                    <AdminNavGroup
                        id="admin-personnel-links"
                        label="Nhân sự"
                        expanded={personnelExpanded}
                        active={isPersonnelRoute}
                        onToggle={() => setPersonnelExpanded((expanded) => !expanded)}
                        links={[
                            ["Bác sĩ", "/admin/doctors"],
                            ["Nhân viên", "/admin/staff"],
                        ]}
                    />
                    <AdminNavGroup
                        id="admin-service-links"
                        label="Dịch vụ"
                        expanded={servicesExpanded}
                        active={isServiceRoute}
                        onToggle={() => setServicesExpanded((expanded) => !expanded)}
                        links={[
                            ["Danh sách dịch vụ", "/admin/services"],
                            ["Danh mục dịch vụ", "/admin/service-categories"],
                        ]}
                    />
                    <AdminNavLink label="Đánh giá" to="/admin/reviews" />
                    <AdminNavGroup
                        id="admin-voucher-links"
                        label="Voucher"
                        expanded={vouchersExpanded}
                        active={isVoucherRoute}
                        onToggle={() => setVouchersExpanded((expanded) => !expanded)}
                        links={[
                            ["Voucher đánh giá", "/admin/vouchers"],
                            ["Quản lý voucher", "/admin/voucher-management"],
                        ]}
                    />
                    <AdminNavLink label="Thông báo" to="/admin/notifications" />
                    <AdminNavGroup
                        id="admin-blog-links"
                        label="Blogs"
                        expanded={blogsExpanded}
                        active={isBlogRoute}
                        onToggle={() => setBlogsExpanded((expanded) => !expanded)}
                        links={[
                            ["Danh sách bài viết", "/admin/blogs"],
                            ["Danh mục / Chủ đề", "/admin/blog-categories"],
                        ]}
                    />
                    <p className="label-luxury mt-3 border-t border-primary-foreground/20 px-3 pt-5 pb-2">
                        Hệ thống
                    </p>
                    <AdminNavLink label="Nhật ký hoạt động" to="/admin/audit-logs" />
                    <button
                        onClick={onLogout}
                        className="flex items-center gap-2 rounded-md px-3 py-3 text-left text-sm opacity-75 transition-colors hover:bg-primary-foreground/10 hover:opacity-100 sm:hidden lg:flex"
                    >
                        <LogOut size={16} />
                        Đăng xuất
                    </button>
                </nav>
            </aside>
            <div className="min-w-0">{children}</div>
        </Container>
    );
}

function AdminNavLink({
    label,
    to,
    exact = false,
}: {
    label: string;
    to: AdminRoute;
    exact?: boolean;
}) {
    return (
        <Link
            to={to}
            activeOptions={{ exact }}
            activeProps={{ className: "bg-card text-primary" }}
            className="rounded-md px-3 py-3 text-sm font-medium transition-colors hover:bg-primary-foreground/10"
        >
            {label}
        </Link>
    );
}

function AdminNavGroup({
    id,
    label,
    expanded,
    active,
    onToggle,
    links,
}: {
    id: string;
    label: string;
    expanded: boolean;
    active: boolean;
    onToggle: () => void;
    links: ReadonlyArray<readonly [string, AdminRoute, boolean?]>;
}) {
    return (
        <div>
            <button
                type="button"
                aria-expanded={expanded}
                aria-controls={id}
                onClick={onToggle}
                className={`flex w-full items-center justify-between rounded-md px-3 py-3 text-left text-sm font-medium transition-colors hover:bg-primary-foreground/10 ${active ? "bg-primary-foreground/10" : ""}`}
            >
                {label}
                <ChevronDown
                    size={16}
                    aria-hidden="true"
                    className={`transition-transform ${expanded ? "rotate-180" : ""}`}
                />
            </button>
            {expanded && (
                <div id={id} className="mt-1 grid gap-1 border-l border-primary-foreground/20 pl-3">
                    {links.map(([name, to, showActiveState = true]) => (
                        <Link
                            key={name}
                            to={to}
                            activeOptions={{ exact: false }}
                            activeProps={{
                                className: showActiveState
                                    ? "bg-card text-primary"
                                    : "text-primary-foreground/85",
                            }}
                            inactiveProps={{ className: "text-primary-foreground/85" }}
                            className="rounded-md px-3 py-2.5 text-sm transition-colors hover:bg-primary-foreground/10"
                        >
                            {name}
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}

function Footer({ showAppointmentLookup }: { showAppointmentLookup: boolean }) {
    return (
        <footer className="bg-navy-deep py-14 text-primary-foreground">
            <Container>
                <div className="grid gap-10 md:grid-cols-[1.4fr_1fr_1fr_1.2fr]">
                    <div>
                        <h2 className="text-2xl">JUNIE</h2>
                        <p className="mt-4 max-w-sm text-sm leading-6 opacity-70">
                            Không gian da liễu thẩm mỹ chuẩn khoa học, đồng hành cùng vẻ đẹp tự
                            nhiên và bền vững.
                        </p>
                        <div className="mt-5 flex gap-4">
                            <Facebook size={18} />
                            <Instagram size={18} />
                        </div>
                    </div>
                    <FooterCol
                        title="Khám phá"
                        links={[
                            ["Dịch vụ", "/services"],
                            ["Bác sĩ", "/doctors"],
                            ["Về chúng tôi", "/#about"],
                            ["Tin tức", "/blogs"],
                        ]}
                    />
                    <FooterCol
                        title="Khách hàng"
                        links={[
                            ["Đặt lịch", "/booking"],
                            ...(showAppointmentLookup
                                ? ([["Tra cứu lịch hẹn", "/appointment-lookup"]] as const)
                                : []),
                            ["Đăng nhập", "/login"],
                        ]}
                    />
                    <div>
                        <p className="label-luxury">Liên hệ</p>
                        <ul className="mt-4 grid gap-3 text-sm opacity-75">
                            <li className="flex gap-2">
                                <MapPin size={16} />
                                Thủ Đức, Thành phố Hồ Chí Minh
                            </li>
                            <li className="flex gap-2">
                                <Phone size={16} />
                                1900 6688
                            </li>
                            <li className="flex gap-2">
                                <Mail size={16} />
                                hello@junie.vn
                            </li>
                            <li className="flex gap-2">
                                <Clock size={16} />
                                08:00–20:00 hằng ngày
                            </li>
                        </ul>
                    </div>
                </div>
                <p className="mt-12 border-t pt-6 text-xs opacity-50">
                    © 2026 Junie Aesthetic & Dermatology. All rights reserved.
                </p>
            </Container>
        </footer>
    );
}

function FooterCol({
    title,
    links,
}: {
    title: string;
    links: ReadonlyArray<readonly [string, string]>;
}) {
    return (
        <div>
            <p className="label-luxury">{title}</p>
            <ul className="mt-4 grid gap-3 text-sm opacity-75">
                {links.map(([label, to]) => (
                    <li key={label}>
                        <Link to={to} className="focus-premium transition-colors hover:text-white">
                            {label}
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}
