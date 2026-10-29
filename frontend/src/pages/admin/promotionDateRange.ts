export function dateFromPromotionInput(value: string | null): Date | undefined {
    if (!value) return undefined;

    const [year, month, day] = value.slice(0, 10).split("-").map(Number);
    if (!year || !month || !day) return undefined;

    return new Date(year, month - 1, day);
}

export function promotionDateInput(date: Date | undefined, time: string): string | null {
    if (!date) return null;

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}T${time}`;
}

export function promotionTimeInput(value: string | null, fallback: string): string {
    return value?.slice(11, 16) || fallback;
}

export function formatPromotionDateTime(value: string | null): string {
    if (!value) return "Chưa chọn";

    const [year, month, day] = value.slice(0, 10).split("-");
    return `${day}/${month}/${year} ${promotionTimeInput(value, "00:00")}`;
}

export function promotionRangeError(start: string | null, end: string | null): string | null {
    return start && end && end <= start ? "Thời gian kết thúc phải sau thời gian bắt đầu." : null;
}
