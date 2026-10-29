import { Link } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { Building2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { dealerApi, dealerKeys } from "./api";

export function DealerNavLink({ onNavigate }: { onNavigate?: () => void }) {
    const { user } = useAuth();
    const enabled = user?.role === "customer";
    const accounts = useQuery({
        queryKey: dealerKeys.mine(user?.id),
        queryFn: dealerApi.mine,
        enabled,
    });
    if (!enabled || !accounts.data?.data.length) return null;

    return (
        <Link
            to="/dealer"
            onClick={onNavigate}
            className="focus-premium flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium text-primary transition-colors hover:bg-muted"
            activeProps={{ className: "bg-primary text-primary-foreground" }}
        >
            <Building2 size={17} />
            <span>Khu vực đại lý →</span>
        </Link>
    );
}
