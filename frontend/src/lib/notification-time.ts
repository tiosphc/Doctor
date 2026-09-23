export function formatNotificationTime(value: string): string {
    const createdAt = new Date(value);
    if (Number.isNaN(createdAt.getTime())) return value;

    const seconds = Math.floor((Date.now() - createdAt.getTime()) / 1000);
    if (seconds < 60) return "Vừa xong";
    if (seconds < 3600) return `${Math.floor(seconds / 60)} phút trước`;
    if (seconds < 86_400) return `${Math.floor(seconds / 3600)} giờ trước`;
    if (seconds < 604_800) return `${Math.floor(seconds / 86_400)} ngày trước`;

    return new Intl.DateTimeFormat("vi-VN", {
        day: "numeric",
        month: "short",
        year: createdAt.getFullYear() === new Date().getFullYear() ? undefined : "numeric",
    }).format(createdAt);
}
