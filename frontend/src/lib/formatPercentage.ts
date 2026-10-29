export function formatPercentage(value: string | number | null | undefined): string {
    const raw = String(value ?? "").trim();
    if (!/^\d+(?:\.\d+)?$/.test(raw)) return "—";

    const [whole, fraction = ""] = raw.split(".");
    const normalizedWhole = (whole ?? "0").replace(/^0+(?=\d)/, "");
    const normalizedFraction = fraction.replace(/0+$/, "");
    return `${normalizedWhole}${normalizedFraction ? `.${normalizedFraction}` : ""}%`;
}

export function formatPromotionDiscount(
    type: "percentage" | "fixed_amount",
    value: string | number,
): string {
    return type === "percentage"
        ? formatPercentage(value)
        : `${Math.round(Number(value)).toLocaleString("vi-VN")} đ`;
}
