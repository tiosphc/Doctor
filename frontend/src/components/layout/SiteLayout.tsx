import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
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
    CircleUserRound,
    Bell,
    Gift,
    CalendarSearch,
    ShoppingCart,
    Package,
    LayoutDashboard,
    ChartNoAxesCombined,
    Users,
    Warehouse,
    ShoppingBag,
    List,
    Store,
    Megaphone,
    UserCog,
    History,
    ClipboardList,
    Stethoscope,
    Star,
    UserRound,
    Building2,
    WalletCards,
    FileClock,
    Tags,
    Layers3,
    BadgePercent,
    Newspaper,
    FolderTree,
    Boxes,
    ExternalLink,
    PanelLeft,
    type LucideIcon,
} from "lucide-react";
import { ButtonLink } from "@/components/common/Button";
import { Container } from "@/components/common/Container";
import { useAuth } from "@/contexts/AuthContext";
import { MobileServiceMenu, ServiceMegaMenu } from "@/components/services/ServiceNavigation";
import { NotificationBell } from "@/components/notifications/NotificationBell";
import { StaffShell } from "@/components/layout/StaffLayout";
import { DealerLayout } from "@/components/layout/DealerLayout";
import { retailCommerceApi, retailKeys } from "@/services/retailCommerceApi";
import { DealerNavLink } from "@/features/dealers/DealerNavLink";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Sheet, SheetContent, SheetTitle } from "@/components/ui/sheet";

const nav = [
    ["Trang chủ", "/"],
    ["Dịch vụ", "/services"],
    ["Sản phẩm", "/products"],
    ["Ưu đãi", "/promotions"],
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
    const admin = path === "/admin" || path.startsWith("/admin/");
    const dealer = path === "/dealer" || path.startsWith("/dealer/");
    const staff =
        path === "/receptionist" ||
        path.startsWith("/receptionist/") ||
        path === "/doctor" ||
        path.startsWith("/doctor/");
    const isServiceDetail = /^\/services\/[^/]+\/[^/]+\/?$/.test(path);
    const hasHeroHeader = path === "/" || (path.startsWith("/services") && !isServiceDetail);
    const transparentHeader = hasHeroHeader && !isScrolled;
    const canLookupAppointments = !isLoading && !user;
    const cartQuery = useQuery({
        queryKey: retailKeys.cart(user?.id),
        queryFn: retailCommerceApi.cart,
        enabled: Boolean(user) && !isLoading && !admin && !dealer,
        staleTime: 30_000,
    });
    const cartCount = cartQuery.data?.data.item_count ?? 0;

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

    if (admin) {
        return <AdminShell onLogout={signOut}>{children}</AdminShell>;
    }
    if (dealer && path !== "/dealer/apply") {
        return <DealerLayout onLogout={signOut}>{children}</DealerLayout>;
    }

    return (
        <div className="min-h-screen">
            <header
                className={`fixed inset-x-0 top-0 z-50 transition-[transform,background-color,box-shadow] duration-300 motion-reduce:transition-none ${headerVisible ? "translate-y-0" : "-translate-y-full"} ${transparentHeader ? "bg-transparent text-white" : "bg-background/90 text-foreground shadow-sm backdrop-blur-xl"}`}
            >
                <Container className="flex h-16 items-center justify-between gap-3 md:h-20 md:gap-4">
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
                        className="hidden items-center justify-center gap-5 lg:flex xl:gap-6"
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
                    <div className="flex items-center justify-end gap-1.5 md:gap-2.5">
                        {!isLoading && user && (
                            <Link
                                to="/cart"
                                aria-label={`Giỏ hàng, ${cartCount} dòng sản phẩm`}
                                className={`focus-premium relative grid size-10 place-items-center rounded-full ${transparentHeader ? "text-white" : "text-primary"}`}
                            >
                                <ShoppingCart size={21} />
                                {cartCount > 0 && (
                                    <span className="absolute -right-1 -top-1 grid min-w-5 place-items-center rounded-full bg-secondary px-1 text-[10px] font-bold text-secondary-foreground">
                                        {cartCount > 99 ? "99+" : cartCount}
                                    </span>
                                )}
                            </Link>
                        )}
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
                                    className={`focus-premium hidden min-w-0 items-center gap-2.5 border-l pl-3 md:flex lg:pl-4 ${transparentHeader ? "border-white/25 text-white" : "border-border text-primary"}`}
                                    aria-label={`Mở ${user.role === "admin" ? "khu vực quản trị" : "tài khoản"}`}
                                >
                                    <span className="grid size-9 place-items-center rounded-full bg-primary text-xs font-semibold text-primary-foreground">
                                        {initials(user.name)}
                                    </span>
                                    <span className="grid gap-0.5 text-left">
                                        <span className="max-w-36 truncate text-xs font-semibold lg:max-w-44">
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
                            {user && (
                                <>
                                    <Link
                                        to="/cart"
                                        onClick={() => setOpen(false)}
                                        className="focus-premium flex items-center gap-2 border-b py-4 font-medium text-primary"
                                    >
                                        <ShoppingCart size={18} /> Giỏ hàng ({cartCount})
                                    </Link>
                                    <Link
                                        to="/my-orders"
                                        onClick={() => setOpen(false)}
                                        className="focus-premium flex items-center gap-2 border-b py-4 font-medium text-primary"
                                    >
                                        <Package size={18} /> Đơn hàng của tôi
                                    </Link>
                                    {user.role === "customer" && (
                                        <DealerNavLink onNavigate={() => setOpen(false)} />
                                    )}
                                </>
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
                {staff ? (
                    <StaffShell onLogout={signOut}>{children}</StaffShell>
                ) : account ? (
                    <AccountShell onLogout={signOut}>{children}</AccountShell>
                ) : (
                    children
                )}
            </main>
            {account ? <AccountFooter /> : <Footer showAppointmentLookup={canLookupAppointments} />}
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
    const links = [
        ["Tổng quan", "/account", Grid2X2],
        ["Lịch hẹn", "/account/appointments", CalendarDays],
        ["Đơn hàng", "/my-orders", Package],
        ["Ưu đãi & thành viên", "/account/vouchers", Gift],
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
                            </Link>
                        ))}
                        <div className="mt-1 border-t pt-1">
                            <DealerNavLink onNavigate={() => setOpen(false)} />
                        </div>
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
                        </Link>
                    ))}
                    <div className="mt-3 border-t pt-3">
                        <DealerNavLink />
                    </div>
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
    | "/admin/reports"
    | "/admin/appointments"
    | "/admin/customers"
    | "/admin/doctors"
    | "/admin/staff"
    | "/admin/services"
    | "/admin/products"
    | "/admin/product-master"
    | "/admin/retail-pricing"
    | "/admin/dealer-pricing"
    | "/admin/dealer-tiers"
    | "/admin/dealer-applications"
    | "/admin/dealers"
    | "/admin/dealer-wallet-top-ups"
    | "/admin/warehouses"
    | "/admin/inventory"
    | "/admin/suppliers"
    | "/admin/purchase-orders"
    | "/admin/sales-orders"
    | "/admin/sales-orders/dealer"
    | "/admin/sales-orders/retail"
    | "/admin/service-categories"
    | "/admin/reviews"
    | "/admin/vouchers"
    | "/admin/voucher-management"
    | "/admin/sales-promotions"
    | "/admin/sales-vouchers"
    | "/admin/notifications"
    | "/admin/blogs"
    | "/admin/blog-categories"
    | "/admin/audit-logs";

type AdminLink = { label: string; to: AdminRoute; icon: LucideIcon };
type AdminNavEntry =
    | ({ kind: "link" } & AdminLink)
    | { kind: "group"; id: string; label: string; icon: LucideIcon; links: AdminLink[] };
type AdminNavSection = { label: string; entries: AdminNavEntry[] };

const adminSections: AdminNavSection[] = [
    {
        label: "QUẢN TRỊ",
        entries: [
            { kind: "link", label: "Tổng quan", to: "/admin", icon: LayoutDashboard },
            { kind: "link", label: "Báo cáo ERP", to: "/admin/reports", icon: ChartNoAxesCombined },
        ],
    },
    {
        label: "KHÁCH HÀNG",
        entries: [
            {
                kind: "group",
                id: "customers",
                label: "Khách hàng & đại lý",
                icon: Users,
                links: [
                    { label: "Khách hàng", to: "/admin/customers", icon: UserRound },
                    { label: "Đại lý", to: "/admin/dealers", icon: Building2 },
                    {
                        label: "Ví đại lý",
                        to: "/admin/dealer-wallet-top-ups",
                        icon: WalletCards,
                    },
                    {
                        label: "Đơn đăng ký đại lý",
                        to: "/admin/dealer-applications",
                        icon: FileClock,
                    },
                    { label: "Tier đại lý", to: "/admin/dealer-tiers", icon: BadgePercent },
                ],
            },
        ],
    },
    {
        label: "BÁN HÀNG",
        entries: [
            {
                kind: "group",
                id: "products",
                label: "Quản lý sản phẩm",
                icon: Package,
                links: [
                    { label: "Danh sách sản phẩm", to: "/admin/products", icon: Package },
                    { label: "Danh mục & đơn vị", to: "/admin/product-master", icon: Layers3 },
                    { label: "Bảng giá Retail", to: "/admin/retail-pricing", icon: Tags },
                    { label: "Bảng giá Đại lý", to: "/admin/dealer-pricing", icon: BadgePercent },
                ],
            },
            {
                kind: "group",
                id: "sales-orders",
                label: "Quản lý đơn hàng",
                icon: ShoppingBag,
                links: [
                    { label: "Tất cả đơn", to: "/admin/sales-orders", icon: List },
                    { label: "Đơn bán lẻ", to: "/admin/sales-orders/retail", icon: Store },
                    { label: "Đơn đại lý", to: "/admin/sales-orders/dealer", icon: Building2 },
                ],
            },
        ],
    },
    {
        label: "KHO & MUA HÀNG",
        entries: [
            {
                kind: "group",
                id: "warehouse",
                label: "Kho",
                icon: Warehouse,
                links: [
                    { label: "Kho hàng", to: "/admin/warehouses", icon: Warehouse },
                    { label: "Tồn kho", to: "/admin/inventory", icon: Boxes },
                ],
            },
            {
                kind: "group",
                id: "procurement",
                label: "Mua hàng",
                icon: ClipboardList,
                links: [
                    { label: "Nhà cung cấp", to: "/admin/suppliers", icon: Building2 },
                    {
                        label: "Đơn mua / Nhận hàng",
                        to: "/admin/purchase-orders",
                        icon: ClipboardList,
                    },
                ],
            },
        ],
    },
    {
        label: "MARKETING",
        entries: [
            {
                kind: "group",
                id: "promotions",
                label: "Khuyến mãi",
                icon: Megaphone,
                links: [
                    { label: "Ưu đãi bán hàng", to: "/admin/sales-promotions", icon: BadgePercent },
                ],
            },
            {
                kind: "group",
                id: "vouchers",
                label: "Voucher",
                icon: Gift,
                links: [
                    { label: "Voucher dịch vụ", to: "/admin/voucher-management", icon: Gift },
                    { label: "Voucher đơn hàng", to: "/admin/sales-vouchers", icon: ShoppingBag },
                    { label: "Voucher đánh giá", to: "/admin/vouchers", icon: BadgePercent },
                ],
            },
            { kind: "link", label: "Thông báo", to: "/admin/notifications", icon: Bell },
            {
                kind: "group",
                id: "content",
                label: "Nội dung",
                icon: Newspaper,
                links: [
                    { label: "Bài viết", to: "/admin/blogs", icon: Newspaper },
                    { label: "Danh mục bài viết", to: "/admin/blog-categories", icon: FolderTree },
                ],
            },
        ],
    },
    {
        label: "VẬN HÀNH",
        entries: [
            {
                kind: "group",
                id: "operations",
                label: "Lịch hẹn & dịch vụ",
                icon: CalendarDays,
                links: [
                    { label: "Lịch hẹn", to: "/admin/appointments", icon: ClipboardList },
                    { label: "Dịch vụ", to: "/admin/services", icon: Stethoscope },
                    { label: "Danh mục dịch vụ", to: "/admin/service-categories", icon: Layers3 },
                    { label: "Đánh giá", to: "/admin/reviews", icon: Star },
                ],
            },
        ],
    },
    {
        label: "HỆ THỐNG",
        entries: [
            {
                kind: "group",
                id: "personnel",
                label: "Nhân sự",
                icon: UserCog,
                links: [
                    { label: "Bác sĩ", to: "/admin/doctors", icon: Stethoscope },
                    { label: "Nhân viên", to: "/admin/staff", icon: Users },
                ],
            },
            { kind: "link", label: "Nhật ký hoạt động", to: "/admin/audit-logs", icon: History },
        ],
    },
];

function adminRouteMatches(path: string, route: AdminRoute): boolean {
    if (route === "/admin/sales-orders") {
        return path === route || path === route + "/" || /^\/admin\/sales-orders\/\d+$/.test(path);
    }
    if (route === "/admin/sales-orders/retail" && path === "/admin/sales-orders/new") {
        return true;
    }
    return path === route || (route !== "/admin" && path.startsWith(route + "/"));
}

function activeAdminGroup(path: string): string | null {
    for (const section of adminSections) {
        for (const entry of section.entries) {
            if (
                entry.kind === "group" &&
                entry.links.some((link) => adminRouteMatches(path, link.to))
            ) {
                return entry.id;
            }
        }
    }
    return null;
}

function adminPageTitle(path: string): string {
    if (path === "/admin") return "Tổng quan hệ thống";
    if (path === "/admin/products/new") return "Thêm sản phẩm";
    if (path === "/admin/sales-orders/new") return "Tạo đơn bán lẻ";
    if (/^\/admin\/sales-orders\/\d+$/.test(path)) return "Chi tiết đơn hàng";
    for (const section of adminSections) {
        for (const entry of section.entries) {
            if (entry.kind === "link" && adminRouteMatches(path, entry.to)) return entry.label;
            if (entry.kind === "group") {
                const active = entry.links.find((link) => adminRouteMatches(path, link.to));
                if (active) return active.label;
            }
        }
    }
    return "Quản trị";
}

function AdminShell({ children, onLogout }: { children: React.ReactNode; onLogout: () => void }) {
    const { user, isLoading } = useAuth();
    const path = useRouterState({ select: (state) => state.location.pathname });
    const [drawerOpen, setDrawerOpen] = useState(false);
    const currentGroup = activeAdminGroup(path);
    const [expandedGroup, setExpandedGroup] = useState<string | null>(currentGroup);

    useEffect(() => {
        setExpandedGroup(currentGroup);
        setDrawerOpen(false);
    }, [path, currentGroup]);

    if (isLoading || user?.role !== "admin") {
        return <div className="min-h-screen bg-background px-5 py-20">{children}</div>;
    }

    const sidebar = (placement: "desktop" | "mobile") => (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex h-16 shrink-0 items-center justify-between border-b border-border/70 px-5">
                <div className="min-w-0">
                    <p className="font-sans text-lg font-semibold tracking-[.12em] text-primary">
                        JUNIE
                    </p>
                    <p className="text-[10px] font-semibold tracking-[.2em] text-secondary">
                        ADMIN
                    </p>
                </div>
            </div>
            <nav
                aria-label="Điều hướng quản trị"
                className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-3 py-5"
            >
                {adminSections.map((section) => (
                    <div key={section.label} className="mb-5">
                        <p className="px-3 pb-2 text-[10px] font-semibold tracking-[.14em] text-muted-foreground">
                            {section.label}
                        </p>
                        <div className="grid gap-0.5">
                            {section.entries.map((entry) =>
                                entry.kind === "link" ? (
                                    <AdminNavLink
                                        key={entry.to}
                                        item={entry}
                                        path={path}
                                        onNavigate={() => setDrawerOpen(false)}
                                    />
                                ) : (
                                    <AdminNavGroup
                                        key={entry.id}
                                        group={entry}
                                        path={path}
                                        expanded={expandedGroup === entry.id}
                                        onToggle={() =>
                                            setExpandedGroup((current) =>
                                                current === entry.id ? null : entry.id,
                                            )
                                        }
                                        onNavigate={() => setDrawerOpen(false)}
                                        panelId={placement + "-" + entry.id}
                                    />
                                ),
                            )}
                        </div>
                    </div>
                ))}
            </nav>
            <div className="shrink-0 border-t border-border/70 p-3">
                <button
                    type="button"
                    onClick={onLogout}
                    className="focus-premium flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-primary"
                >
                    <LogOut size={17} />
                    Đăng xuất
                </button>
            </div>
        </div>
    );

    return (
        <div className="admin-portal min-h-screen bg-[#faf9f6] text-foreground">
            <aside className="fixed inset-y-0 left-0 z-40 hidden w-72 flex-col border-r border-border/70 bg-white shadow-sm lg:flex">
                {sidebar("desktop")}
            </aside>
            <Sheet open={drawerOpen} onOpenChange={setDrawerOpen}>
                <SheetContent
                    id="admin-mobile-navigation"
                    side="left"
                    className="w-[min(18rem,85vw)] bg-white p-0 lg:hidden"
                >
                    <SheetTitle className="sr-only">Menu quản trị</SheetTitle>
                    {sidebar("mobile")}
                </SheetContent>
            </Sheet>
            <div className="min-w-0 lg:pl-72">
                <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-3 border-b border-border/70 bg-white px-4 shadow-sm sm:px-6 lg:px-8">
                    <div className="flex min-w-0 items-center gap-3">
                        <button
                            type="button"
                            aria-label="Mở menu quản trị"
                            aria-expanded={drawerOpen}
                            aria-controls="admin-mobile-navigation"
                            onClick={() => setDrawerOpen(true)}
                            className="focus-premium grid size-9 shrink-0 place-items-center rounded-lg text-primary hover:bg-muted lg:hidden"
                        >
                            <Menu size={20} />
                        </button>
                        <PanelLeft
                            size={17}
                            className="hidden text-muted-foreground lg:block"
                            aria-hidden="true"
                        />
                        <span className="truncate text-sm font-semibold text-primary sm:text-base">
                            {adminPageTitle(path)}
                        </span>
                    </div>
                    <div className="flex shrink-0 items-center gap-1 sm:gap-3">
                        <Link
                            to="/"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="focus-premium hidden items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-xs font-medium text-primary transition-colors hover:bg-muted sm:inline-flex"
                        >
                            Xem website <ExternalLink size={14} />
                        </Link>
                        <NotificationBell />
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    aria-label="Menu tài khoản quản trị"
                                    className="focus-premium flex items-center gap-2 rounded-lg px-1.5 py-1 text-primary hover:bg-muted"
                                >
                                    <span className="grid size-8 place-items-center rounded-full bg-primary text-xs font-semibold text-primary-foreground">
                                        {initials(user.name)}
                                    </span>
                                    <span className="hidden max-w-32 truncate text-sm font-medium md:block">
                                        {user.name}
                                    </span>
                                    <ChevronDown
                                        size={14}
                                        className="hidden text-muted-foreground sm:block"
                                    />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-48">
                                <DropdownMenuItem asChild>
                                    <Link to="/" target="_blank" rel="noopener noreferrer">
                                        Xem website
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem onSelect={onLogout} className="text-red-700">
                                    <LogOut size={15} /> Đăng xuất
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </header>
                <main className="mx-auto w-full max-w-[1600px] min-w-0 px-4 py-7 sm:px-6 lg:px-8">
                    {children}
                </main>
            </div>
        </div>
    );
}

function AdminNavLink({
    item,
    path,
    onNavigate,
    nested = false,
    tree = false,
}: {
    item: AdminLink;
    path: string;
    onNavigate: () => void;
    nested?: boolean;
    tree?: boolean;
}) {
    const Icon = item.icon;
    const active = adminRouteMatches(path, item.to);
    return (
        <Link
            to={item.to}
            onClick={onNavigate}
            aria-current={active ? "page" : undefined}
            className={[
                "focus-premium flex min-w-0 items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition-colors",
                tree
                    ? "relative py-1.5 pl-4 text-[13px] before:absolute before:left-0 before:top-1/2 before:w-3 before:border-t before:border-border/70"
                    : nested
                      ? "ml-3 py-2 text-[13px]"
                      : "font-medium",
                active
                    ? "bg-[#eef2fb] font-semibold text-primary"
                    : "text-foreground/75 hover:bg-muted hover:text-primary",
            ].join(" ")}
        >
            <Icon size={nested ? 15 : 17} className="shrink-0" aria-hidden="true" />
            <span className="min-w-0 break-words">{item.label}</span>
        </Link>
    );
}

function AdminNavGroup({
    group,
    path,
    expanded,
    onToggle,
    onNavigate,
    panelId,
}: {
    group: Extract<AdminNavEntry, { kind: "group" }>;
    path: string;
    expanded: boolean;
    onToggle: () => void;
    onNavigate: () => void;
    panelId: string;
}) {
    const Icon = group.icon;
    const active = group.links.some((link) => adminRouteMatches(path, link.to));
    return (
        <div>
            <button
                type="button"
                aria-expanded={expanded}
                aria-controls={panelId}
                onClick={onToggle}
                className={[
                    "focus-premium flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium transition-colors",
                    active
                        ? "bg-[#f5f2e9] text-primary"
                        : "text-foreground/85 hover:bg-muted hover:text-primary",
                ].join(" ")}
            >
                <Icon size={17} className="shrink-0" aria-hidden="true" />
                <span className="min-w-0 flex-1 break-words">{group.label}</span>
                <ChevronDown
                    size={15}
                    className={
                        expanded
                            ? "shrink-0 rotate-180 transition-transform"
                            : "shrink-0 transition-transform"
                    }
                    aria-hidden="true"
                />
            </button>
            {expanded && (
                <div
                    id={panelId}
                    className={
                        group.id === "marketing"
                            ? "ml-5 mt-0.5 grid gap-0.5 border-l border-border/70"
                            : "mt-0.5 grid gap-0.5 border-l border-border/70 pl-1"
                    }
                >
                    {group.links.map((link) => (
                        <AdminNavLink
                            key={link.to}
                            item={link}
                            path={path}
                            nested
                            tree={group.id === "marketing"}
                            onNavigate={onNavigate}
                        />
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
