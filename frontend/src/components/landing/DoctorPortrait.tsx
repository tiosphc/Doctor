import { useState } from "react";
import { Stethoscope } from "lucide-react";
import type { Doctor } from "@/types";

export function DoctorPortrait({
    doctor,
    className = "",
}: {
    doctor?: Doctor | undefined;
    className?: string;
}) {
    const [imageFailed, setImageFailed] = useState(false);

    return (
        <div
            className={`relative overflow-hidden rounded-[3px] border border-border bg-cream ${className}`}
        >
            {doctor?.avatar && !imageFailed ? (
                <img
                    src={doctor.avatar}
                    alt={`Chân dung ${doctor.name}`}
                    loading="lazy"
                    decoding="async"
                    onError={() => setImageFailed(true)}
                    className="h-full w-full object-cover"
                />
            ) : (
                <div className="flex h-full min-h-[200px] flex-col items-center justify-center gap-4 px-6 text-center text-mocha">
                    <span
                        className="grid size-16 place-items-center rounded-full border border-champagne/50"
                        aria-hidden="true"
                    >
                        <Stethoscope size={27} strokeWidth={1.1} />
                    </span>
                    <span className="max-w-44 text-xs leading-5 tracking-wide">
                        Ảnh chân dung bác sĩ sẽ được cập nhật
                    </span>
                </div>
            )}
        </div>
    );
}
