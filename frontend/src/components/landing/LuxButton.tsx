import type { ReactNode } from "react";
import { ArrowUpRight } from "lucide-react";
import { useMagnetic } from "@/hooks/use-lux-motion";

type Props = {
    href: string;
    children: ReactNode;
    variant?: "solid" | "line" | "ivory";
    className?: string;
};

const styles = {
    solid: "bg-primary text-primary-foreground hover:bg-mocha",
    line: "text-espresso border-b border-espresso/40 hover:border-espresso",
    ivory: "bg-ivory text-espresso hover:bg-cream",
};

export function LuxButton({ href, children, variant = "solid", className = "" }: Props) {
    const ref = useMagnetic<HTMLAnchorElement>(variant === "line" ? 0.12 : 0.22);
    const pad = variant === "line" ? "px-7 py-4" : "px-7 py-4";
    return (
        <a
            ref={ref}
            href={href}
            className={`group inline-flex items-center justify-between gap-4 ${pad} eyebrow transition-colors duration-500 ${styles[variant]} ${className}`}
        >
            <span className="flex-1 text-center">{children}</span>
            <span className="relative inline-flex h-4 w-4 overflow-hidden">
                <ArrowUpRight
                    strokeWidth={1.25}
                    className="h-4 w-4 transition-transform duration-500 group-hover:translate-x-4 group-hover:-translate-y-4"
                />
                <ArrowUpRight
                    strokeWidth={1.25}
                    className="absolute h-4 w-4 -translate-x-4 translate-y-4 transition-transform duration-500 group-hover:translate-x-0 group-hover:translate-y-0"
                />
            </span>
        </a>
    );
}
