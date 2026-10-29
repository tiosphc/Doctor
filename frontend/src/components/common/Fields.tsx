import type { InputHTMLAttributes, TextareaHTMLAttributes } from "react";
export function Input({ className = "", ...props }: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            className={`min-h-12 w-full rounded-md border border-input bg-card px-4 text-sm outline-none transition focus:border-primary focus:ring-1 focus:ring-primary ${className}`}
            {...props}
        />
    );
}
export function Textarea({
    className = "",
    ...props
}: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return (
        <textarea
            className={`min-h-28 w-full rounded-md border border-input bg-card px-4 py-3 text-sm outline-none transition focus:border-primary focus:ring-1 focus:ring-primary ${className}`}
            {...props}
        />
    );
}
export function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string | undefined;
    children: React.ReactNode;
}) {
    return (
        <label className="grid gap-2 text-sm font-medium text-foreground">
            <span>{label}</span>
            {children}
            {error && <span className="text-xs text-red-700">{error}</span>}
        </label>
    );
}
