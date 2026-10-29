/** Product quantities are counted in whole sellable units. */
export function isPositiveProductQuantity(value: string): boolean {
    return /^[1-9][0-9]*$/.test(value) && Number.isSafeInteger(Number(value));
}

export function isSignedProductQuantity(value: string): boolean {
    return /^-?[1-9][0-9]*$/.test(value) && Number.isSafeInteger(Number(value));
}

export function isNonNegativeProductQuantity(value: string): boolean {
    return value === "0" || isPositiveProductQuantity(value);
}

/** Keep a historical fractional value visible instead of silently rounding it. */
export function formatProductQuantity(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === "") return "—";
    const text = String(value);
    return /^-?(?:0|[1-9][0-9]*)(?:\.0+)?$/.test(text) ? String(Number(text)) : text;
}
