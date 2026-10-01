import { Link } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { Building2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { dealerApplicationApi, dealerApplicationKeys } from "@/features/dealer-applications/api";
import { dealerApi, dealerKeys } from "./api";

export function DealerNavLink({ onNavigate }: { onNavigate?: () => void }) {
    const { user } = useAuth();
    const enabled = user?.role === "customer";
    const accounts = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled,
    });
    const application = useQuery({
        queryKey: dealerApplicationKeys.mine(user?.id),
        queryFn: dealerApplicationApi.mine,
        enabled,
        retry: false,
    });

    if (!enabled) return null;

    const hasDealer =
        Boolean(accounts.data?.data.length) || application.data?.data.status === "approved";
    const status = application.data?.data.status;
    const label = hasDealer
        ? "Khu vực đại lý →"
        : status === "pending"
          ? "Trạng thái đăng ký"
          : status === "rejected"
            ? "Đăng ký lại đại lý"
            : "Đăng ký đại lý";

    return (
        <Link
            to={hasDealer ? "/dealer" : "/dealer/apply"}
            onClick={onNavigate}
            className="focus-premium flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium text-primary transition-colors hover:bg-muted"
            activeProps={{ className: "bg-primary text-primary-foreground" }}
        >
            <Building2 size={17} />
            <span>{label}</span>
        </Link>
    );
}
