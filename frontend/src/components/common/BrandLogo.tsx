type BrandLogoProps = {
    variant?: "small" | "compact" | "display";
    className?: string;
};

export function BrandLogo({ variant = "compact", className = "" }: BrandLogoProps) {
    const width =
        variant === "display"
            ? "w-full max-w-[570px]"
            : variant === "small"
              ? "w-20"
              : "w-[112px] md:w-[124px]";

    return (
        <span
            className={`relative inline-block aspect-[2.2/1] shrink-0 overflow-hidden align-middle ${width} ${className}`}
        >
            <img
                src="/junie-logo.png"
                alt="Junie Beauty Clinic"
                className="absolute top-1/2 left-1/2 h-auto w-[165%] max-w-none -translate-x-1/2 -translate-y-1/2"
            />
        </span>
    );
}
