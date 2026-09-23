import type { ButtonHTMLAttributes, ReactNode } from "react";
import { Link } from "@tanstack/react-router";
import { cn } from "@/lib/utils";
type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: "primary" | "outline" | "ghost";
    children: ReactNode;
};
export function Button({ variant = "primary", className, children, ...props }: Props) {
    return (
        <button
            className={cn(
                "focus-premium inline-flex min-h-11 items-center justify-center gap-2 rounded-full px-5 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50",
                variant === "primary" &&
                    "border border-navy-deep bg-primary text-primary-foreground hover:bg-navy-deep",
                variant === "outline" &&
                    "border border-secondary bg-transparent text-primary hover:bg-muted",
                variant === "ghost" && "text-primary hover:bg-muted",
                className,
            )}
            {...props}
        >
            {children}
        </button>
    );
}
export function ButtonLink({
    to,
    children,
    variant = "primary",
    className,
}: {
    to: string;
    children: ReactNode;
    variant?: Props["variant"];
    className?: string;
}) {
    return (
        <Link
            to={to}
            className={cn(
                "focus-premium inline-flex min-h-11 items-center justify-center gap-2 rounded-full px-5 text-sm font-semibold transition-colors",
                variant === "primary" &&
                    "border border-navy-deep bg-primary text-primary-foreground hover:bg-navy-deep",
                variant === "outline" && "border border-secondary text-primary hover:bg-muted",
                variant === "ghost" && "text-primary hover:bg-muted",
                className,
            )}
        >
            {children}
        </Link>
    );
}
