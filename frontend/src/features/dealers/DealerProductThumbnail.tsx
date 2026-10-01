import { ImageOff } from "lucide-react";

export function DealerProductThumbnail({ src, name }: { src: string | null; name: string }) {
    return (
        <div className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted sm:size-16">
            {src ? (
                <img src={src} alt={name} className="size-full object-cover" loading="lazy" />
            ) : (
                <ImageOff size={20} className="text-muted-foreground" aria-hidden="true" />
            )}
        </div>
    );
}
