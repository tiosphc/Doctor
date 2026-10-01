import { useQuery } from "@tanstack/react-query";
import { Link, Navigate } from "@tanstack/react-router";
import { FileSpreadsheet, Package, ShoppingBag } from "lucide-react";
import { EmptyState, ErrorState, LoadingState } from "@/components/common/AsyncState";
import { useAuth } from "@/contexts/AuthContext";
import { errorMessage } from "@/services/api";
import { dealerApi, dealerKeys } from "./api";

export function DealerDashboardPage() {
    const { user, isLoading } = useAuth();
    const accounts = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled: user?.role === "customer",
    });
    const account = accounts.data?.data[0];

    if (isLoading) return <LoadingState />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== "customer") return <Navigate to="/account" />;
    if (accounts.isPending) return <LoadingState />;
    if (accounts.isError)
        return (
            <ErrorState
                message={errorMessage(accounts.error)}
                retry={() => void accounts.refetch()}
            />
        );
    if (!account)
        return (
            <EmptyState message="Bạn chưa có tài khoản đại lý đang hoạt động. Vui lòng đăng ký đại lý từ khu vực tài khoản." />
        );

    const shortcuts = [
        {
            label: "Sản phẩm",
            description: "Xem sản phẩm và giá theo hạng của bạn.",
            to: "/dealer/products" as const,
            icon: Package,
        },
        {
            label: "Đặt hàng nhanh",
            description: "Tạo đơn hàng đại lý từ SKU.",
            to: "/dealer/quick-order" as const,
            icon: ShoppingBag,
            search: { sku: "" },
        },
        {
            label: "Nhập đơn Excel",
            description: "Tải lên và rà soát đơn hàng theo tệp Excel.",
            to: "/dealer/import-orders" as const,
            icon: FileSpreadsheet,
        },
        {
            label: "Đơn hàng",
            description: "Theo dõi và quản lý các đơn hàng đã tạo.",
            to: "/dealer/orders" as const,
            icon: ShoppingBag,
        },
    ];

    return (
        <div className="mx-auto max-w-6xl space-y-7">
            <header className="rounded-2xl bg-[#092b5c] px-6 py-8 text-white sm:px-8 sm:py-10">
                <p className="text-xs font-semibold tracking-[.16em] text-[#e5c28c]">
                    JUNIE DEALER PORTAL
                </p>
                <h1 className="mt-3 text-3xl font-semibold sm:text-4xl">Xin chào, {user.name}</h1>
                <p className="mt-3 max-w-2xl text-sm leading-6 text-white/75">
                    Quản lý việc mua hàng, đơn hàng và thông tin đại lý của bạn tại Junie.
                </p>
            </header>
            <section>
                <div className="mb-4">
                    <p className="text-xs font-semibold tracking-[.14em] text-[#bc9151]">
                        TRUY CẬP NHANH
                    </p>
                    <h2 className="mt-1 text-2xl font-semibold text-[#092b5c]">
                        Mua hàng cùng Junie
                    </h2>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    {shortcuts.map((item) => {
                        const Icon = item.icon;
                        return (
                            <Link
                                key={item.to}
                                to={item.to}
                                {...("search" in item ? { search: item.search } : {})}
                                className="group rounded-2xl border border-[#d8e0eb] bg-white p-5 transition-shadow hover:shadow-md"
                            >
                                <span className="grid size-11 place-items-center rounded-xl bg-[#eaf0fa] text-[#092b5c]">
                                    <Icon size={20} />
                                </span>
                                <h3 className="mt-4 font-semibold text-[#092b5c] group-hover:text-[#bc9151]">
                                    {item.label}
                                </h3>
                                <p className="mt-1 text-sm leading-6 text-[#68758a]">
                                    {item.description}
                                </p>
                            </Link>
                        );
                    })}
                </div>
            </section>
        </div>
    );
}
