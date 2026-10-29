import { useRef, useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ArrowUpRight } from "lucide-react";
import { BrandLogo } from "@/components/common/BrandLogo";
import portraitImg from "@/assets/lovable/portrait.jpg";
import roomImg from "@/assets/lovable/space-room.jpg";
import heroImg from "@/assets/lovable/hero-arch.jpg";
import consultImg from "@/assets/lovable/service-consult.jpg";
import { servicePath } from "@/components/services/ServiceExplorer";
import { serviceApi } from "@/services/serviceApi";
import { errorMessage } from "@/services/api";
import type { Service } from "@/types";
import { LuxButton } from "./LuxButton";
import { SectionLabel } from "./SectionLabel";
import "./Services.css";
import "./Experience.css";
export { About } from "./About";

/* 03 — Services */
const cardLayouts = [
    { span: "md:col-span-5 md:row-span-2", ratio: "aspect-[4/5] md:aspect-auto md:h-full" },
    { span: "md:col-span-4", ratio: "aspect-[4/5]" },
    { span: "md:col-span-3 md:mt-24", ratio: "aspect-[3/4]" },
    { span: "md:col-span-7", ratio: "aspect-[16/9]" },
] as const;

function servicePrice(service: Service): string {
    const amount = Number(service.price);
    if (!service.price || !Number.isFinite(amount) || amount < 0) return "Liên hệ báo giá";
    return `${new Intl.NumberFormat("vi-VN").format(amount)} ₫`;
}

function ServiceMedia({ service, className = "" }: { service: Service; className?: string }) {
    const [failedImage, setFailedImage] = useState<string | null>(null);
    const image = service.image;

    return (
        <>
            <div className="junie-services__image-empty absolute inset-0" aria-hidden="true" />
            {image && image !== failedImage && (
                <img
                    src={image}
                    alt={service.name}
                    loading="lazy"
                    onError={() => setFailedImage(image)}
                    className={`absolute inset-0 h-full w-full object-cover ${className}`}
                />
            )}
        </>
    );
}

export function Services() {
    const sliderRef = useRef<HTMLDivElement>(null);
    const [slide, setSlide] = useState(0);
    const servicesQuery = useQuery({
        queryKey: ["services", { lovableHome: true }],
        queryFn: () => serviceApi.list(),
    });
    const services = servicesQuery.data?.data.slice(0, 4) ?? [];

    useEffect(() => {
        if (services.length === 0) return;
        const cards = document.querySelectorAll(".junie-services .reveal");
        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add("is-in");
                        observer.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.15 },
        );
        cards.forEach((card) => observer.observe(card));
        return () => observer.disconnect();
    }, [services.length]);

    useEffect(() => {
        const el = sliderRef.current;
        if (!el) return;
        let frame = 0;
        const update = () => {
            frame = 0;
            const first = el.firstElementChild as HTMLElement | null;
            if (!first) return;
            const gap = parseFloat(getComputedStyle(el).columnGap) || 14;
            const step = first.offsetWidth + gap;
            setSlide(Math.max(0, Math.min(services.length - 1, Math.round(el.scrollLeft / step))));
        };
        const onScroll = () => {
            if (!frame) frame = requestAnimationFrame(update);
        };
        el.addEventListener("scroll", onScroll, { passive: true });
        return () => {
            el.removeEventListener("scroll", onScroll);
            cancelAnimationFrame(frame);
        };
    }, [services.length]);

    const goTo = (i: number) => {
        const el = sliderRef.current;
        const first = el?.firstElementChild as HTMLElement | null;
        if (!el || !first) return;
        const gap = parseFloat(getComputedStyle(el).columnGap) || 14;
        el.scrollTo({ left: i * (first.offsetWidth + gap), behavior: "smooth" });
    };

    return (
        <section
            id="dich-vu"
            className="junie-services grain relative bg-cream px-6 py-28 md:px-10 md:py-40"
        >
            <div className="mx-auto max-w-[1400px]">
                <div className="junie-services__heading grid gap-10 md:grid-cols-12 md:items-end">
                    <div className="md:col-span-7">
                        <div className="reveal">
                            <SectionLabel label="Liệu trình đặc trưng" />
                        </div>
                        <h2 className="junie-services__title font-display reveal mt-10 text-[clamp(2.6rem,5.5vw,5.5rem)]">
                            Chăm sóc theo
                            <br />
                            <span className="italic text-mocha">cách riêng</span> của bạn.
                        </h2>
                    </div>
                    <p className="reveal text-[0.95rem] leading-relaxed text-muted-foreground md:col-span-4 md:col-start-9">
                        Mỗi liệu trình là một lời mời chậm lại, được cân nhắc kỹ lưỡng cho riêng làn
                        da và nhịp sống của bạn.
                    </p>
                </div>

                {servicesQuery.isPending && (
                    <p role="status" className="junie-services__state">
                        Đang tải dịch vụ...
                    </p>
                )}
                {servicesQuery.isError && (
                    <div role="alert" className="junie-services__state">
                        <p>{errorMessage(servicesQuery.error)}</p>
                        <button
                            type="button"
                            onClick={() => servicesQuery.refetch()}
                            className="link-lux mt-4"
                        >
                            Thử lại
                        </button>
                    </div>
                )}
                {servicesQuery.isSuccess && services.length === 0 && (
                    <p className="junie-services__state">Hiện chưa có dịch vụ để hiển thị.</p>
                )}

                {/* Mobile — compact swipe slider */}
                <div className="junie-services__slider md:hidden" ref={sliderRef}>
                    {services.map((s, i) => (
                        <a key={s.id} href={servicePath(s)} className="junie-services__slide">
                            <div className="junie-services__slide-media relative">
                                <ServiceMedia service={s} />
                            </div>
                            <div className="junie-services__slide-body">
                                <span className="eyebrow">{String(i + 1).padStart(2, "0")}</span>
                                <h3 className="junie-services__name font-display">{s.name}</h3>
                                <div className="junie-services__slide-foot">
                                    <span className="junie-services__price">{servicePrice(s)}</span>
                                    <span className="junie-services__action eyebrow">
                                        Khám phá{" "}
                                        <ArrowUpRight className="h-3 w-3" strokeWidth={1.25} />
                                    </span>
                                </div>
                            </div>
                        </a>
                    ))}
                </div>
                <div className="junie-services__dots md:hidden">
                    {services.map((s, i) => (
                        <button
                            key={s.id}
                            type="button"
                            data-current={slide === i}
                            aria-label={`Chuyển đến dịch vụ ${i + 1}`}
                            aria-current={slide === i}
                            onClick={() => goTo(i)}
                        />
                    ))}
                </div>
                <a href="/services" className="junie-services__all eyebrow link-lux md:hidden">
                    Khám phá tất cả dịch vụ <ArrowUpRight className="h-3 w-3" strokeWidth={1.25} />
                </a>

                {/* Desktop / tablet grid */}
                <div className="junie-services__grid mt-20 hidden gap-6 md:grid md:grid-cols-12 md:gap-8">
                    {services.map((s, i) => (
                        <a
                            key={s.id}
                            href={servicePath(s)}
                            className={`junie-services__card group reveal relative block overflow-hidden ${cardLayouts[i]?.span ?? ""}`}
                            style={{ transitionDelay: `${i * 120}ms` }}
                        >
                            <div
                                className={`junie-services__image relative w-full overflow-hidden ${cardLayouts[i]?.ratio ?? ""}`}
                            >
                                <ServiceMedia
                                    service={s}
                                    className="transition-transform duration-[1.6s] ease-[var(--ease-lux)] group-hover:scale-[1.06]"
                                />
                                <div className="absolute inset-0 bg-gradient-to-t from-espresso/75 via-espresso/10 to-transparent opacity-80 transition-opacity duration-700 group-hover:opacity-100" />
                                <div className="junie-services__content absolute inset-x-0 bottom-0 p-6 text-ivory md:p-8">
                                    <span className="eyebrow text-sand">
                                        {String(i + 1).padStart(2, "0")}
                                    </span>
                                    <div className="junie-services__name-price">
                                        <h3 className="junie-services__name font-display mt-3 text-3xl md:text-4xl">
                                            {s.name}
                                        </h3>
                                        <span className="junie-services__price">
                                            {servicePrice(s)}
                                        </span>
                                    </div>
                                    <div className="grid grid-rows-[1fr] transition-all duration-700 md:grid-rows-[0fr] md:group-hover:grid-rows-[1fr]">
                                        <p className="overflow-hidden text-sm leading-relaxed text-ivory/80">
                                            <span className="block pt-3">
                                                {s.short_description || s.description}
                                            </span>
                                        </p>
                                    </div>
                                    <span className="junie-services__action eyebrow mt-5 inline-flex items-center gap-2 border-b border-ivory/40 pb-1">
                                        Khám phá{" "}
                                        <ArrowUpRight
                                            className="h-3.5 w-3.5 transition-transform duration-500 group-hover:-translate-y-0.5 group-hover:translate-x-0.5"
                                            strokeWidth={1.25}
                                        />
                                    </span>
                                </div>
                            </div>
                        </a>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* 04 — Experience (sticky storytelling) */
const steps = [
    {
        n: "01",
        t: "Lắng nghe",
        d: "Một cuộc trò chuyện chậm rãi để hiểu làn da, thói quen và những điều bạn mong muốn.",
        img: consultImg,
    },
    {
        n: "02",
        t: "Thấu hiểu",
        d: "Phân tích kỹ lưỡng và cùng bạn xây dựng một hướng chăm sóc dành riêng.",
        img: portraitImg,
    },
    {
        n: "03",
        t: "Chăm sóc",
        d: "Từng liệu trình được thực hiện tỉ mỉ trong không gian tĩnh lặng, ấm áp.",
        img: roomImg,
    },
    {
        n: "04",
        t: "Đồng hành",
        d: "Theo dõi và điều chỉnh cùng bạn, để vẻ đẹp được nuôi dưỡng bền vững theo thời gian.",
        img: heroImg,
    },
];

export function Experience() {
    const [active, setActive] = useState(0);
    const refs = useRef<(HTMLDivElement | null)[]>([]);
    const headingRef = useRef<HTMLDivElement>(null);
    const trackRef = useRef<HTMLDivElement>(null);
    const stageRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const mobile = window.matchMedia("(max-width: 767px)");
        const heading = headingRef.current;
        const section = heading?.closest("section");
        let frame = 0;
        const measureHeading = () => {
            if (!heading || !section) return;
            section.style.setProperty(
                "--junie-exp-heading-height",
                `${heading.getBoundingClientRect().height}px`,
            );
        };
        const update = () => {
            frame = 0;
            const track = trackRef.current;
            const stage = stageRef.current;
            if (!mobile.matches || !track || !stage) return;
            const travel = Math.max(1, track.offsetHeight - stage.offsetHeight);
            const stickyTop = Number.parseFloat(window.getComputedStyle(stage).top);
            if (!Number.isFinite(stickyTop)) return;
            const progress = Math.max(
                0,
                Math.min(1, (stickyTop - track.getBoundingClientRect().top) / travel),
            );
            setActive(Math.min(steps.length - 1, Math.floor(progress * steps.length)));
        };
        const onScroll = () => {
            if (!frame) frame = requestAnimationFrame(update);
        };
        const headingObserver = new ResizeObserver(() => {
            measureHeading();
            onScroll();
        });
        if (heading) headingObserver.observe(heading);
        measureHeading();
        const io = new IntersectionObserver(
            (es) =>
                es.forEach(
                    (e) =>
                        !mobile.matches &&
                        e.isIntersecting &&
                        setActive(Number((e.target as HTMLElement).dataset["i"])),
                ),
            { rootMargin: "-45% 0px -45% 0px" },
        );
        refs.current.forEach((r) => r && io.observe(r));
        window.addEventListener("scroll", onScroll, { passive: true });
        window.addEventListener("resize", onScroll);
        update();
        return () => {
            io.disconnect();
            headingObserver.disconnect();
            section?.style.removeProperty("--junie-exp-heading-height");
            window.removeEventListener("scroll", onScroll);
            window.removeEventListener("resize", onScroll);
            cancelAnimationFrame(frame);
        };
    }, []);

    return (
        <section id="khong-gian" className="junie-experience relative px-6 py-28 md:px-10 md:py-40">
            <div className="mx-auto max-w-[1400px]">
                <div
                    ref={trackRef}
                    className="junie-exp__grid mt-20 grid gap-12 md:grid-cols-2 md:gap-20"
                    data-active={active}
                >
                    <div
                        ref={stageRef}
                        className="junie-exp__sticky sticky top-24 h-[calc(100svh-8rem)]"
                    >
                        <div ref={headingRef} className="junie-exp__heading">
                            <div className="reveal">
                                <SectionLabel label="Trải nghiệm Junie" />
                            </div>
                            <h2 className="junie-exp__title font-display reveal mt-10 max-w-4xl text-[clamp(2.6rem,5.5vw,5.5rem)]">
                                Mỗi trải nghiệm,{" "}
                                <span className="italic text-mocha">một sự tinh tế.</span>
                            </h2>
                        </div>
                        <div className="junie-exp__picture arch relative h-full overflow-hidden">
                            {steps.map((s, i) => (
                                <img
                                    key={s.n}
                                    src={s.img}
                                    alt={s.t}
                                    loading="lazy"
                                    aria-hidden={active !== i}
                                    className={`absolute inset-0 h-full w-full object-cover transition-all duration-[1.4s] ease-[var(--ease-lux)] ${
                                        active === i
                                            ? "scale-100 opacity-100"
                                            : "scale-110 opacity-0"
                                    }`}
                                />
                            ))}
                            <div className="junie-exp__caption absolute bottom-8 left-1/2 -translate-x-1/2 rounded-full bg-ivory/80 px-5 py-2 backdrop-blur-sm">
                                <span className="eyebrow">
                                    {steps[active]?.n} — {steps[active]?.t}
                                </span>
                            </div>
                        </div>
                        <div className="junie-exp__mobile-copy md:hidden">
                            <div className="junie-exp__progress" aria-hidden="true">
                                {steps.map((s, i) => (
                                    <span key={s.n} data-current={active === i} />
                                ))}
                            </div>
                            {steps.map((s, i) => (
                                <div
                                    className="junie-exp__mobile-panel"
                                    key={s.n}
                                    data-current={active === i}
                                    aria-hidden={active !== i}
                                >
                                    <span className="eyebrow text-mocha">{s.n} / 04</span>
                                    <h3 className="font-display">{s.t}</h3>
                                    <p>{s.d}</p>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="junie-exp__steps">
                        {steps.map((s, i) => (
                            <div
                                key={s.n}
                                data-i={i}
                                ref={(el) => {
                                    refs.current[i] = el;
                                }}
                                className="junie-exp__step flex min-h-[60svh] flex-col justify-center border-t border-border py-12 md:min-h-[80svh]"
                            >
                                <img
                                    src={s.img}
                                    alt={s.t}
                                    loading="lazy"
                                    className="arch mb-8 aspect-[4/5] w-3/4 object-cover md:hidden"
                                />
                                <span
                                    className={`eyebrow transition-colors duration-700 ${active === i ? "text-mocha" : "text-champagne"}`}
                                >
                                    {s.n}
                                </span>
                                <h3
                                    className={`junie-exp__step-title font-display mt-4 text-6xl transition-all duration-700 md:text-8xl ${active === i ? "text-espresso" : "md:text-espresso/25"}`}
                                >
                                    {s.t}
                                </h3>
                                <p className="mt-6 max-w-sm text-[0.95rem] leading-relaxed text-muted-foreground">
                                    {s.d}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}

/* 05 — Invitation */
export function Invitation() {
    return (
        <section
            id="lien-he"
            className="grain relative overflow-hidden bg-mocha px-6 py-28 text-ivory md:px-10 md:py-40"
        >
            <div className="mx-auto grid max-w-[1400px] items-center gap-16 md:grid-cols-12">
                <div className="md:col-span-7">
                    <div className="reveal">
                        <SectionLabel label="Lời mời" light />
                    </div>
                    <h2 className="font-display reveal mt-10 text-[clamp(2.8rem,6.5vw,6.75rem)]">
                        Dành một khoảnh khắc
                        <br />
                        <span className="italic text-sand">cho chính mình.</span>
                    </h2>
                    <p className="reveal mt-8 max-w-md text-[0.95rem] leading-relaxed text-ivory/80">
                        Khởi đầu hành trình chăm sóc vẻ đẹp của bạn cùng Junie.
                    </p>
                    <div className="reveal mt-12 flex flex-wrap items-center gap-8">
                        <LuxButton href="/booking" variant="ivory">
                            Đặt lịch tư vấn
                        </LuxButton>
                        <a
                            href="/services"
                            className="eyebrow link-lux text-ivory/85 hover:text-ivory"
                        >
                            Khám phá dịch vụ
                        </a>
                    </div>
                </div>
                <div className="junie-invitation__visual relative hidden md:block md:col-span-5">
                    <div
                        className="junie-invitation__visual-edge absolute -inset-4 arch border border-champagne/40"
                        aria-hidden
                    />
                    <div className="junie-invitation__frame reveal arch aspect-[3/4] overflow-hidden">
                        <div className="junie-invitation__inner h-full w-full">
                            <img
                                src={roomImg}
                                alt="Không gian Junie trong ánh chiều ấm"
                                loading="lazy"
                                width={1536}
                                height={1024}
                                className="junie-invitation__img h-full w-full object-cover"
                            />
                        </div>
                    </div>
                    <p className="junie-invitation__caption reveal mt-5 text-center font-serif italic text-sm text-ivory/80">
                        không gian tĩnh lặng, dành riêng cho bạn
                    </p>
                </div>
            </div>
        </section>
    );
}

/* Footer */
export function Footer() {
    return (
        <footer className="bg-ivory px-6 pb-10 pt-24 md:px-10">
            <div className="mx-auto max-w-[1400px]">
                <div className="grid gap-12 border-b border-border pb-16 md:grid-cols-12">
                    <p className="font-serif text-2xl italic leading-snug text-mocha md:col-span-4">
                        “Sang trọng không nằm ở sự phô trương, mà ở vẻ đẹp của từng chi tiết được
                        nâng niu.”
                    </p>
                    <div className="md:col-span-2 md:col-start-7">
                        <p className="eyebrow text-champagne">Khám phá</p>
                        <ul className="mt-5 space-y-3 text-sm">
                            {[
                                ["/#ve-junie", "Về Junie"],
                                ["/#dich-vu", "Dịch vụ"],
                                ["/#khong-gian", "Không gian"],
                                ["/#lien-he", "Liên hệ"],
                            ].map(([h, l]) => (
                                <li key={h}>
                                    <a href={h} className="link-lux">
                                        {l}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="md:col-span-2">
                        <p className="eyebrow text-champagne">Liên hệ</p>
                        <ul className="mt-5 space-y-3 text-sm text-muted-foreground">
                            <li>Địa chỉ: đang cập nhật</li>
                            <li>Điện thoại: đang cập nhật</li>
                            <li>Email: đang cập nhật</li>
                        </ul>
                    </div>
                    <div className="md:col-span-2">
                        <p className="eyebrow text-champagne">Theo dõi</p>
                        <ul className="mt-5 space-y-3 text-sm">
                            {["Instagram", "Facebook", "TikTok"].map((s) => (
                                <li key={s}>
                                    <a href="#" className="link-lux">
                                        {s}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
                <div className="flex justify-center py-8 md:py-10">
                    <BrandLogo variant="display" />
                </div>
                <div className="flex flex-col justify-between gap-3 text-xs text-muted-foreground sm:flex-row">
                    <span>© {new Date().getFullYear()} Junie. Bảo lưu mọi quyền.</span>
                    <span className="eyebrow text-[0.6rem]">Beauty in Stillness</span>
                </div>
            </div>
        </footer>
    );
}
