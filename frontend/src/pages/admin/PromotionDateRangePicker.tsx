import { useState } from "react";
import { ArrowRight, CalendarDays } from "lucide-react";
import type { DateRange } from "react-day-picker";
import { Calendar } from "@/components/ui/calendar";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { fieldClass, secondaryButtonClass, buttonClass } from "./ProductAdminShared";
import {
    dateFromPromotionInput,
    formatPromotionDateTime,
    promotionDateInput,
    promotionRangeError,
    promotionTimeInput,
} from "./promotionDateRange";

type PromotionDateRangePickerProps = {
    start: string | null;
    end: string | null;
    onChange: (start: string | null, end: string | null) => void;
    errors: string[];
};

export function PromotionDateRangePicker({
    start,
    end,
    onChange,
    errors,
}: PromotionDateRangePickerProps) {
    const [open, setOpen] = useState(false);
    const [range, setRange] = useState<DateRange>();
    const [startTime, setStartTime] = useState("00:00");
    const [endTime, setEndTime] = useState("23:59");
    const [localError, setLocalError] = useState<string | null>(null);

    const changeOpen = (nextOpen: boolean) => {
        if (nextOpen) {
            setRange({
                from: dateFromPromotionInput(start),
                to: dateFromPromotionInput(end),
            });
            setStartTime(promotionTimeInput(start, "00:00"));
            setEndTime(promotionTimeInput(end, "23:59"));
            setLocalError(null);
        }
        setOpen(nextOpen);
    };

    const apply = () => {
        if ((range?.from && !startTime) || (range?.to && !endTime)) {
            setLocalError("Vui lòng chọn giờ cho các ngày đã chọn.");
            return;
        }
        const nextStart = promotionDateInput(range?.from, startTime);
        const nextEnd = promotionDateInput(range?.to, endTime);
        const error = promotionRangeError(nextStart, nextEnd);
        if (error) {
            setLocalError(error);
            return;
        }
        onChange(nextStart, nextEnd);
        setOpen(false);
    };

    return (
        <div className="grid gap-1 text-sm">
            <Popover open={open} onOpenChange={changeOpen}>
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        aria-label="Chọn thời gian áp dụng"
                        aria-invalid={errors.length > 0}
                        className={`${fieldClass} flex min-h-11 items-center gap-3 text-left text-sm font-normal ${errors.length ? "border-red-600" : ""}`}
                    >
                        <CalendarDays
                            className="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        {start || end ? (
                            <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                <span>{formatPromotionDateTime(start)}</span>
                                <ArrowRight
                                    className="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span>{formatPromotionDateTime(end)}</span>
                            </span>
                        ) : (
                            <span className="text-muted-foreground">Chọn thời gian áp dụng</span>
                        )}
                    </button>
                </PopoverTrigger>
                <PopoverContent align="start" className="w-[min(92vw,24rem)] p-3 sm:w-auto">
                    <p className="admin-subsection-title px-1 text-primary">
                        Chọn ngày bắt đầu → kết thúc
                    </p>
                    <Calendar
                        mode="range"
                        selected={range}
                        onSelect={(nextRange) => {
                            setRange(nextRange);
                            setLocalError(null);
                        }}
                        {...(range?.from || range?.to
                            ? { defaultMonth: range.from ?? range.to! }
                            : {})}
                        className="mx-auto"
                    />
                    <div className="grid gap-3 border-t pt-3 sm:grid-cols-2">
                        <label className="admin-form-label grid gap-1.5">
                            Giờ bắt đầu
                            <input
                                className={`${fieldClass} text-sm`}
                                type="time"
                                value={startTime}
                                onChange={(event) => setStartTime(event.target.value)}
                            />
                        </label>
                        <label className="admin-form-label grid gap-1.5">
                            Giờ kết thúc
                            <input
                                className={`${fieldClass} text-sm`}
                                type="time"
                                value={endTime}
                                onChange={(event) => setEndTime(event.target.value)}
                            />
                        </label>
                    </div>
                    {localError && (
                        <p role="alert" className="mt-2 text-xs text-red-700">
                            {localError}
                        </p>
                    )}
                    <div className="mt-4 flex flex-wrap justify-end gap-2 border-t pt-3">
                        <button
                            type="button"
                            className={secondaryButtonClass}
                            onClick={() => {
                                onChange(null, null);
                                setOpen(false);
                            }}
                        >
                            Xóa thời gian
                        </button>
                        <button
                            type="button"
                            className={secondaryButtonClass}
                            onClick={() => setOpen(false)}
                        >
                            Hủy
                        </button>
                        <button type="button" className={buttonClass} onClick={apply}>
                            Áp dụng
                        </button>
                    </div>
                </PopoverContent>
            </Popover>
            {errors.map((error, index) => (
                <span
                    key={`${index}-${error}`}
                    data-promotion-error="true"
                    className="text-red-700"
                >
                    {error}
                </span>
            ))}
        </div>
    );
}
