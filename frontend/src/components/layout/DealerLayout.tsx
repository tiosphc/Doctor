import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useNavigate, useRouterState } from "@tanstack/react-router";
import {
    Building2,
    ChevronDown,
    CircleDollarSign,
    Crown,
    ExternalLink,
    FileSpreadsheet,
    LayoutDashboard,
    LogOut,
    Menu,
    Package,
    PanelLeft,
    ShoppingBag,
    Gift,
    UserRound,
} from "lucide-react";
import { NotificationBell } from "@/components/notifications/NotificationBell";
import { useAuth } from "@/contexts/AuthContext";
import { dealerApi, dealerKeys } from "@/features/dealers/api";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Sheet, SheetContent, SheetTitle } from "@/components/ui/sheet";

type DealerRoute =
    | "/dealer"
    | "/dealer/products"
    | "/dealer/promotions"
    | "/dealer/quick-order"
    | "/dealer/import-orders"
    | "/dealer/orders"
    | "/dealer/top-up"
    | "/dealer/profile";

type DealerNavItem = {
    label: string;
    to: DealerRoute;
    icon: typeof LayoutDashboard;
    search?: { sku: string };
};

const dealerSections: { label?: string; items: DealerNavItem[] }[] = [
    { items: [{ label: "Tổng quan", to: "/dealer", icon: LayoutDashboard }] },
    {
        label: "MUA HÀNG",
        items: [
            { label: "Sản phẩm", to: "/dealer/products", icon: Package },
            { label: "Ưu đãi", to: "/dealer/promotions", icon: Gift },
            {
                label: "Đặt hàng nhanh",
                to: "/dealer/quick-order",
                icon: ShoppingBag,
                search: { sku: "" },
            },
            { label: "Nhập đơn Excel", to: "/dealer/import-orders", icon: FileSpreadsheet },
        ],
    },
    { label: "ĐƠN HÀNG", items: [{ label: "Đơn hàng", to: "/dealer/orders", icon: ShoppingBag }] },
    {
        label: "TÀI CHÍNH",
        items: [{ label: "Nạp tiền", to: "/dealer/top-up", icon: CircleDollarSign }],
    },
    {
        label: "TÀI KHOẢN",
        items: [{ label: "Thông tin đại lý", to: "/dealer/profile", icon: Building2 }],
    },
];

function initials(name: string): string {
    return name
        .trim()
        .split(/\s+/)
        .slice(-2)
        .map((part) => part[0])
        .join("")
        .toUpperCase();
}

function routeMatches(path: string, route: DealerRoute): boolean {
    return path === route || (route !== "/dealer" && path.startsWith(`${route}/`));
}

function pageTitle(path: string): string {
    return (
        dealerSections
            .flatMap((section) => section.items)
            .find((item) => routeMatches(path, item.to))?.label ?? "Khu vực đại lý"
    );
}

export function DealerLayout({
    children,
    onLogout,
}: {
    children: React.ReactNode;
    onLogout: () => void;
}) {
    const { user, isLoading } = useAuth();
    const path = useRouterState({ select: (state) => state.location.pathname });
    const navigate = useNavigate();
    const [drawerOpen, setDrawerOpen] = useState(false);
    const accounts = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled: user?.role === "customer",
    });
    const account = accounts.data?.data[0];
    const wallet = useQuery({
        queryKey: dealerKeys.wallet(user?.id, account?.id ?? 0),
        queryFn: () => dealerApi.wallet(account!.id),
        enabled: Boolean(account),
        refetchInterval: 10000,
    });
    const tier = useQuery({
        queryKey: dealerKeys.tier(user?.id, account?.id ?? 0),
        queryFn: () => dealerApi.tier(account!.id),
        enabled: Boolean(account),
        refetchInterval: 30000,
    });
    const balance = wallet.isPending
        ? "…"
        : wallet.isError
          ? "—"
          : `${new Intl.NumberFormat("vi-VN").format(Number(wallet.data?.data.available_balance ?? 0))}đ`;
    const tierCode = tier.isPending
        ? "…"
        : tier.isError
          ? "—"
          : tier.data?.data.effective_tier?.status === "active"
            ? tier.data.data.effective_tier.code.toUpperCase()
            : "CHƯA CÓ HẠNG";
    const tierColor =
        tierCode === "GOLD"
            ? "border-amber-200 bg-amber-50 text-amber-800"
            : tierCode === "SILVER"
              ? "border-slate-200 bg-slate-50 text-slate-700"
              : tierCode === "DIAMOND"
                ? "border-cyan-200 bg-cyan-50 text-cyan-800"
                : "border-[#d8e0eb] bg-[#f7f9fc] text-[#64748b]";

    useEffect(() => setDrawerOpen(false), [path]);

    if (isLoading || user?.role !== "customer") {
        return <div className="min-h-screen bg-background px-5 py-20">{children}</div>;
    }

    async function logout(): Promise<void> {
        await onLogout();
        await navigate({ to: "/" });
    }

    const sidebar = () => (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex h-20 shrink-0 items-center border-b border-[#d8e0eb] px-6">
                <Link to="/dealer" onClick={() => setDrawerOpen(false)}>
                    <p className="font-display text-lg font-semibold tracking-[.14em] text-[#092b5c]">
                        JUNIE
                    </p>
                    <p className="mt-0.5 text-[10px] font-semibold tracking-[.2em] text-[#bc9151]">
                        DEALER PORTAL
                    </p>
                </Link>
            </div>
            <nav
                aria-label="Điều hướng đại lý"
                className="min-h-0 flex-1 overflow-y-auto px-4 py-5"
            >
                {dealerSections.map((section, index) => (
                    <section
                        key={section.label ?? "overview"}
                        className={index === 0 ? "" : "mt-6"}
                    >
                        {section.label && (
                            <p className="mb-2 px-3 text-[11px] font-semibold tracking-[.12em] text-[#52627a]">
                                {section.label}
                            </p>
                        )}
                        <div className="grid gap-1">
                            {section.items.map((item) => {
                                const Icon = item.icon;
                                const active = routeMatches(path, item.to);
                                return (
                                    <Link
                                        key={item.to}
                                        to={item.to}
                                        {...(item.search ? { search: item.search } : {})}
                                        onClick={() => setDrawerOpen(false)}
                                        aria-current={active ? "page" : undefined}
                                        className={`focus-premium flex min-h-11 min-w-0 items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-medium transition-colors ${active ? "bg-[#eaf0fa] font-semibold text-[#092b5c]" : "text-[#4e5b70] hover:bg-[#f3f6fa] hover:text-[#092b5c]"}`}
                                    >
                                        <Icon size={19} className="shrink-0" aria-hidden="true" />
                                        <span className="min-w-0 truncate">{item.label}</span>
                                    </Link>
                                );
                            })}
                        </div>
                    </section>
                ))}
            </nav>
            <div className="shrink-0 border-t border-[#d8e0eb] p-4">
                <div className="grid gap-1 pb-3">
                    <Link
                        to="/account"
                        onClick={() => setDrawerOpen(false)}
                        className="focus-premium flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-medium text-[#5d687a] transition-colors hover:bg-[#f3f6fa] hover:text-[#092b5c]"
                    >
                        <UserRound size={18} /> Tài khoản cá nhân
                    </Link>
                    <Link
                        to="/"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="focus-premium flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-medium text-[#5d687a] transition-colors hover:bg-[#f3f6fa] hover:text-[#092b5c]"
                    >
                        <ExternalLink size={18} /> Về website Junie
                    </Link>
                </div>
                <button
                    type="button"
                    onClick={() => void logout()}
                    className="focus-premium flex min-h-11 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-[15px] font-medium text-[#5d687a] transition-colors hover:bg-[#f3f6fa] hover:text-[#092b5c]"
                >
                    <LogOut size={18} /> Đăng xuất
                </button>
            </div>
        </div>
    );

    return (
        <div className="dealer-portal min-h-screen bg-[#f7f9fc] text-foreground">
            <aside className="fixed inset-y-0 left-0 z-40 hidden w-72 flex-col border-r border-[#d8e0eb] bg-white lg:flex">
                {sidebar()}
            </aside>
            <Sheet open={drawerOpen} onOpenChange={setDrawerOpen}>
                <SheetContent
                    id="dealer-mobile-navigation"
                    side="left"
                    className="w-[min(18rem,85vw)] border-[#d8e0eb] bg-white p-0 lg:hidden"
                >
                    <SheetTitle className="sr-only">Menu đại lý</SheetTitle>
                    {sidebar()}
                </SheetContent>
            </Sheet>
            <div className="min-w-0 lg:pl-72">
                <header className="sticky top-0 z-30 flex min-h-20 flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-[#d8e0eb] bg-white px-3 py-2 shadow-sm sm:px-6 lg:px-8">
                    <div className="flex min-w-0 items-center gap-3 max-sm:flex-1">
                        <button
                            type="button"
                            aria-label="Mở menu đại lý"
                            aria-expanded={drawerOpen}
                            aria-controls="dealer-mobile-navigation"
                            onClick={() => setDrawerOpen(true)}
                            className="focus-premium grid size-10 shrink-0 place-items-center rounded-xl text-[#092b5c] hover:bg-[#f3f6fa] lg:hidden"
                        >
                            <Menu size={21} />
                        </button>
                        <PanelLeft
                            size={18}
                            className="hidden text-[#6b778c] lg:block"
                            aria-hidden="true"
                        />
                        <span className="hidden truncate text-sm font-semibold text-[#092b5c] sm:block sm:text-base">
                            {pageTitle(path)}
                        </span>
                    </div>
                    <div className="flex min-w-0 flex-wrap items-center justify-end gap-1 sm:gap-2 lg:flex-nowrap lg:gap-3">
                        <Link
                            to="/dealer/top-up"
                            className="focus-premium hidden min-h-10 items-center gap-1.5 rounded-xl border border-[#d9b478] bg-[#fffaf1] px-3 py-2 text-sm font-semibold text-[#7b5019] transition-colors hover:bg-[#fdf1db] sm:inline-flex"
                        >
                            <CircleDollarSign size={16} /> Nạp tiền
                        </Link>
                        {account && (
                            <>
                                <span
                                    className="inline-flex min-h-10 items-center gap-2 whitespace-nowrap rounded-xl border border-[#cbd8e8] bg-[#f3f7fc] px-3 py-1 text-base font-bold tabular-nums text-[#092b5c] shadow-sm sm:px-4"
                                    aria-label={`Số dư ví: ${balance}`}
                                >
                                    <span className="hidden text-xs font-medium text-[#5d6f89] lg:inline">
                                        Số dư
                                    </span>
                                    <span>{balance}</span>
                                </span>
                                <span
                                    className={`inline-flex min-h-9 items-center gap-1.5 whitespace-nowrap rounded-full border px-3 py-1 text-xs font-bold tracking-[.1em] shadow-sm ${tierColor}`}
                                    aria-label={`Hạng đại lý: ${tierCode}`}
                                >
                                    <Crown size={14} strokeWidth={1.8} aria-hidden="true" />
                                    {tierCode}
                                </span>
                            </>
                        )}
                        <NotificationBell />
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    aria-label="Menu tài khoản đại lý"
                                    className="focus-premium flex min-h-10 items-center gap-2 rounded-xl px-1.5 py-1 text-[#092b5c] hover:bg-[#f3f6fa]"
                                >
                                    <span className="grid size-9 place-items-center rounded-full bg-[#092b5c] text-xs font-semibold text-white">
                                        {initials(user.name)}
                                    </span>
                                    <span className="hidden max-w-20 truncate text-sm font-medium lg:block xl:max-w-32 2xl:max-w-40">
                                        {account?.trading_name || account?.legal_name || user.name}
                                    </span>
                                    <ChevronDown
                                        size={15}
                                        className="hidden text-[#6b778c] sm:block"
                                    />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-52">
                                <DropdownMenuItem asChild>
                                    <Link to="/dealer/profile">
                                        <Building2 size={16} /> Thông tin đại lý
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <Link to="/account">
                                        <UserRound size={16} /> Tài khoản của tôi
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <Link to="/" target="_blank" rel="noopener noreferrer">
                                        <ExternalLink size={16} /> Xem website
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() => void logout()}
                                    className="text-red-700 focus:text-red-700"
                                >
                                    <LogOut size={16} /> Đăng xuất
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </header>
                <main className="mx-auto w-full max-w-[1600px] min-w-0 px-4 py-7 sm:px-6 lg:px-8 xl:py-8">
                    {children}
                </main>
            </div>
        </div>
    );
}
