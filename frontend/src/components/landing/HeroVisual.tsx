import { useEffect, useRef, useState } from "react";
import careImg from "@/assets/lovable/hero-care-editorial.jpg";
import portraitImg from "@/assets/lovable/portrait.jpg";
import skincareImg from "@/assets/lovable/service-skincare.jpg";
import { HERO_IMAGE_INTERVAL, nextHeroImage } from "@/lib/hero-rotation";
import "./HeroVisual.css";

const images = [
    { src: careImg, alt: "Khoảnh khắc chăm sóc da nhẹ nhàng trong ánh sáng tự nhiên" },
    { src: portraitImg, alt: "Vẻ đẹp tự nhiên và làn da trong ánh nắng ấm" },
];

export function HeroVisual() {
    const ref = useRef<HTMLDivElement>(null);
    const [active, setActive] = useState(0);
    const [paused, setPaused] = useState(false);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const pointerArea = el.closest("section") ?? el;
        const motion = window.matchMedia("(prefers-reduced-motion: reduce)");
        const finePointer = window.matchMedia("(hover: hover) and (pointer: fine)");
        let disposed = false;
        let loaded = false;
        let visible = true;
        let timer: ReturnType<typeof setInterval> | undefined;
        let raf = 0;
        const reset = () => {
            cancelAnimationFrame(raf);
            el.style.setProperty("--pointer-x", "0px");
            el.style.setProperty("--pointer-y", "0px");
        };
        const sync = () => {
            if (timer) clearInterval(timer);
            timer = undefined;
            const stop = motion.matches || !visible || document.hidden;
            setPaused(stop);
            if (!stop && loaded) {
                timer = setInterval(
                    () => setActive((value) => nextHeroImage(value, images.length)),
                    HERO_IMAGE_INTERVAL,
                );
            }
            if (stop) reset();
        };
        void Promise.all(
            images.map(
                ({ src }) =>
                    new Promise<void>((resolve) => {
                        const img = new Image();
                        img.onload = () => {
                            void img
                                .decode()
                                .catch(() => undefined)
                                .then(() => resolve());
                        };
                        img.onerror = () => resolve();
                        img.src = src;
                    }),
            ),
        ).then(() => {
            if (disposed) return;
            loaded = true;
            sync();
        });
        const observer = new IntersectionObserver(([entry]) => {
            visible = entry?.isIntersecting ?? false;
            sync();
        });
        observer.observe(el);
        const move = (event: PointerEvent) => {
            if (motion.matches || !finePointer.matches) return;
            cancelAnimationFrame(raf);
            raf = requestAnimationFrame(() => {
                const rect = el.getBoundingClientRect();
                if (
                    event.clientX < rect.left ||
                    event.clientX > rect.right ||
                    event.clientY < rect.top ||
                    event.clientY > rect.bottom
                ) {
                    reset();
                    return;
                }
                el.style.setProperty(
                    "--pointer-x",
                    `${((event.clientX - rect.left) / rect.width - 0.5) * 12}px`,
                );
                el.style.setProperty(
                    "--pointer-y",
                    `${((event.clientY - rect.top) / rect.height - 0.5) * 10}px`,
                );
            });
        };
        pointerArea.addEventListener("pointermove", move);
        pointerArea.addEventListener("pointerleave", reset);
        motion.addEventListener("change", sync);
        document.addEventListener("visibilitychange", sync);
        sync();
        return () => {
            disposed = true;
            if (timer) clearInterval(timer);
            cancelAnimationFrame(raf);
            observer.disconnect();
            pointerArea.removeEventListener("pointermove", move);
            pointerArea.removeEventListener("pointerleave", reset);
            motion.removeEventListener("change", sync);
            document.removeEventListener("visibilitychange", sync);
        };
    }, []);

    return (
        <div ref={ref} className="junie-hero-visual" data-paused={paused}>
            <div className="junie-hero-visual__main-layer">
                <div className="junie-hero-visual__edge" aria-hidden="true" />
                <div className="junie-hero-visual__main">
                    {images.map((image, index) => (
                        <div
                            key={image.src}
                            className="junie-hero-visual__slide"
                            data-active={active === index}
                            aria-hidden={active !== index}
                        >
                            <img
                                src={image.src}
                                alt={image.alt}
                                width={1024}
                                height={1280}
                                fetchPriority={index === 0 ? "high" : "low"}
                                loading="eager"
                                decoding="async"
                            />
                        </div>
                    ))}
                </div>
            </div>
            <svg
                className="junie-hero-visual__motif"
                viewBox="0 0 140 140"
                fill="none"
                aria-hidden="true"
            >
                <path d="M30 8C63-8 18 66 58 104C97 141 156 85 112 35M105 41l14-10M31 7l8 15" />
            </svg>
            <div className="junie-hero-visual__secondary-layer">
                <figure className="junie-hero-visual__secondary">
                    <div className="junie-hero-visual__detail">
                        <img
                            src={skincareImg}
                            alt="Chai serum và hũ kem dưỡng trong ánh sáng tự nhiên"
                            width={1024}
                            height={1280}
                            loading="lazy"
                            decoding="async"
                        />
                    </div>
                    <figcaption>sự tinh tế trong từng chi tiết</figcaption>
                </figure>
            </div>
        </div>
    );
}
