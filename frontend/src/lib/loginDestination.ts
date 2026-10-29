import type { UserRole } from "@/types";

export function loginDestination(
    role: UserRole,
    hasDealerAccount: boolean,
    returnTo: string | null,
): string {
    if (role === "admin") return "/admin";
    if (role === "doctor") return "/doctor";
    if (role === "receptionist") return "/receptionist";

    const home = hasDealerAccount ? "/dealer" : "/account";
    if (
        !returnTo ||
        !returnTo.startsWith("/") ||
        returnTo.startsWith("//") ||
        returnTo.includes("\\") ||
        returnTo.includes("#")
    ) {
        return home;
    }

    const target = new URL(returnTo, "https://junie.local");
    const path = target.pathname;
    const customerPath =
        /^\/(?:account(?:\/|$)|cart(?:\/|$)|checkout(?:\/|$)|my-orders(?:\/|$)|products(?:\/|$)|booking(?:\/|$))/.test(
            path,
        );
    const dealerPath = hasDealerAccount && /^\/dealer(?:\/|$)/.test(path);

    return customerPath || dealerPath ? path + target.search : home;
}
