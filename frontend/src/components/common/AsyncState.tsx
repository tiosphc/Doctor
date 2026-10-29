import { LoaderCircle, TriangleAlert } from "lucide-react";
import { Button } from "./Button";

export function LoadingState({ label = "Đang tải dữ liệu..." }: { label?: string }) {
    return (
        <div className="flex min-h-40 items-center justify-center gap-3 text-sm text-muted-foreground">
            <LoaderCircle className="animate-spin" size={20} />
            {label}
        </div>
    );
}

export function ErrorState({ message, retry }: { message: string; retry?: () => void }) {
    return (
        <div className="card-surface flex min-h-40 flex-col items-center justify-center p-6 text-center">
            <TriangleAlert className="text-red-700" />
            <p className="mt-3 text-sm text-muted-foreground">{message}</p>
            {retry && (
                <Button variant="outline" className="mt-4" onClick={retry}>
                    Thử lại
                </Button>
            )}
        </div>
    );
}

export function EmptyState({ message }: { message: string }) {
    return (
        <div className="card-surface p-8 text-center text-sm text-muted-foreground">{message}</div>
    );
}

export function Pagination({
    current,
    last,
    onPage,
}: {
    current: number;
    last: number;
    onPage: (page: number) => void;
}) {
    if (last <= 1) return null;
    return (
        <div className="mt-6 flex items-center justify-center gap-3">
            <Button variant="outline" disabled={current <= 1} onClick={() => onPage(current - 1)}>
                Trang trước
            </Button>
            <span className="text-sm text-muted-foreground">
                Trang {current}/{last}
            </span>
            <Button
                variant="outline"
                disabled={current >= last}
                onClick={() => onPage(current + 1)}
            >
                Trang sau
            </Button>
        </div>
    );
}
