import { useEffect, useRef, useState } from "react";

/** Adds `is-in` to .reveal / .reveal-arch / .reveal-mask elements as they enter view. */
export function useScrollReveal() {
    useEffect(() => {
        const els = document.querySelectorAll(".reveal, .reveal-arch, .reveal-mask");
        const io = new IntersectionObserver(
            (entries) =>
                entries.forEach((e) => {
                    if (e.isIntersecting) {
                        e.target.classList.add("is-in");
                        io.unobserve(e.target);
                    }
                }),
            { threshold: 0.15, rootMargin: "0px 0px -8% 0px" },
        );
        els.forEach((el) => io.observe(el));
        return () => io.disconnect();
    }, []);
}

/** Returns window scrollY (rAF throttled). */
export function useScrollY() {
    const [y, setY] = useState(0);
    useEffect(() => {
        let raf = 0;
        const on = () => {
            cancelAnimationFrame(raf);
            raf = requestAnimationFrame(() => setY(window.scrollY));
        };
        on();
        window.addEventListener("scroll", on, { passive: true });
        return () => {
            cancelAnimationFrame(raf);
            window.removeEventListener("scroll", on);
        };
    }, []);
    return y;
}

/** Restrained magnetic pull on fine pointers only. */
export function useMagnetic<T extends HTMLElement>(strength = 0.25) {
    const ref = useRef<T>(null);
    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        if (!window.matchMedia("(pointer: fine)").matches) return;
        if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
        el.style.transition = "transform 0.6s cubic-bezier(0.22,1,0.36,1)";
        const move = (e: MouseEvent) => {
            const r = el.getBoundingClientRect();
            const x = e.clientX - (r.left + r.width / 2);
            const y = e.clientY - (r.top + r.height / 2);
            el.style.transform = `translate(${x * strength}px, ${y * strength}px)`;
        };
        const leave = () => (el.style.transform = "");
        el.addEventListener("mousemove", move);
        el.addEventListener("mouseleave", leave);
        return () => {
            el.removeEventListener("mousemove", move);
            el.removeEventListener("mouseleave", leave);
        };
    }, [strength]);
    return ref;
}
