import { CircleCheck } from "lucide-react";
import { cn } from "@/lib/utils";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";

export function SuccessDialog({
    message,
    onClose,
    title = "Thao tác thành công",
}: {
    message: string;
    onClose: () => void;
    title?: string | undefined;
}) {
    return (
        <Dialog open={message !== ""} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="w-[calc(100%-2rem)] max-w-md rounded-2xl border-emerald-200 p-0 shadow-2xl">
                <div className="flex gap-4 p-6 pr-12">
                    <span className="grid size-11 shrink-0 place-items-center rounded-full bg-emerald-100 text-emerald-700">
                        <CircleCheck size={23} aria-hidden="true" />
                    </span>
                    <DialogHeader className="gap-2 text-left">
                        <DialogTitle className="text-emerald-800">{title}</DialogTitle>
                        <DialogDescription className="leading-6 text-emerald-700">
                            {message}
                        </DialogDescription>
                    </DialogHeader>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export function OperationNotice({
    message,
    success,
    onClose,
    className,
    title,
}: {
    message: string;
    success: boolean;
    onClose: () => void;
    className?: string;
    title?: string | undefined;
}) {
    if (!message) return null;

    if (success) {
        return <SuccessDialog message={message} onClose={onClose} title={title} />;
    }

    return (
        <p role="alert" className={cn("rounded-md bg-red-50 p-3 text-sm text-red-700", className)}>
            {message}
        </p>
    );
}
