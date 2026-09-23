import type { ReactNode } from "react";
export function Container({
    children,
    className = "",
}: {
    children: ReactNode;
    className?: string;
}) {
    return <div className={`page-container ${className}`}>{children}</div>;
}
export function SectionHeading({
    eyebrow,
    title,
    description,
    center = false,
}: {
    eyebrow?: string;
    title: string;
    description?: string;
    center?: boolean;
}) {
    return (
        <div className={center ? "mx-auto max-w-2xl text-center" : "max-w-2xl"}>
            {eyebrow && <p className="label-luxury mb-3">{eyebrow}</p>}
            <h2 className="text-3xl font-semibold leading-tight text-primary md:text-5xl">
                {title}
            </h2>
            {description && (
                <p className="mt-4 text-sm leading-6 text-muted-foreground md:text-base">
                    {description}
                </p>
            )}
        </div>
    );
}
