import { HeroVisual } from "./HeroVisual";
import { LuxButton } from "./LuxButton";

export function Hero() {
    return (
        <section
            id="trang-chu"
            className="grain relative flex min-h-[100svh] flex-col overflow-hidden bg-[radial-gradient(120%_90%_at_85%_20%,var(--cream)_0%,var(--ivory)_55%,var(--sand)_140%)]"
        >
            {/* opening light veil */}
            <div className="anim-page-reveal pointer-events-none absolute inset-0 z-40 bg-ivory" />

            {/* fine architectural grid lines */}
            <div className="pointer-events-none absolute inset-0 z-0 hidden md:block">
                <div className="absolute inset-y-0 left-[8%] w-px bg-champagne/25" />
                <div className="absolute inset-y-0 left-[46%] w-px bg-champagne/15" />
                <div className="absolute inset-x-0 bottom-[14%] h-px bg-champagne/20" />
            </div>

            <HeroVisual />

            {/* TEXT */}
            <div className="relative order-1 z-20 mx-auto flex w-full md:min-h-[100svh] max-w-[1600px] flex-col px-6 pt-28 md:px-10 md:pt-0 md:justify-center">
                <div className="md:max-w-[52%] md:pl-[6%]">
                    <p
                        className="anim-fade-up eyebrow flex items-center gap-4 text-mocha"
                        style={{ animationDelay: "0.9s" }}
                    >
                        <span className="h-px w-10 bg-champagne" />
                        Junie · Aesthetic &amp; Wellness
                    </p>

                    <h1 className="font-display mt-6 text-espresso md:mt-10">
                        <span className="block overflow-hidden pb-2">
                            <span
                                className="anim-line block text-[clamp(3.4rem,8.2vw,8.75rem)]"
                                style={{ animationDelay: "1.05s" }}
                            >
                                Vẻ đẹp riêng.
                            </span>
                        </span>
                        <span className="block overflow-hidden pb-4">
                            <span
                                className="anim-line block pl-[8%] text-[clamp(2.4rem,5.4vw,5.75rem)] text-espresso/85"
                                style={{ animationDelay: "1.25s" }}
                            >
                                Một cách{" "}
                                <em className="whitespace-nowrap font-serif italic text-mocha text-[1.22em]">
                                    nâng niu.
                                </em>
                            </span>
                        </span>
                    </h1>

                    <p
                        className="anim-fade-up mt-6 max-w-md text-[0.95rem] leading-relaxed text-muted-foreground md:mt-8 md:pl-[8%]"
                        style={{ animationDelay: "1.6s" }}
                    >
                        Vẻ đẹp tự nhiên được trân trọng bằng sự tinh tế, chuyên môn và những trải
                        nghiệm chăm sóc dành riêng cho bạn.
                    </p>

                    <div
                        className="anim-fade-up mt-8 grid w-full max-w-[480px] grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-1 md:mt-10 md:pl-[8%] lg:grid-cols-2"
                        style={{ animationDelay: "1.8s" }}
                    >
                        <LuxButton
                            href="/booking"
                            className="w-full !gap-2 !px-3 !text-xs !tracking-[0.08em] whitespace-nowrap [&>span:last-child]:shrink-0"
                        >
                            Đặt lịch tư vấn
                        </LuxButton>
                        <LuxButton
                            href="#dich-vu"
                            variant="line"
                            className="w-full !gap-2 !px-3 !text-xs !tracking-[0.08em] whitespace-nowrap [&>span:last-child]:shrink-0"
                        >
                            Khám phá dịch vụ
                        </LuxButton>
                    </div>
                </div>
            </div>

            {/* bottom micro-typography */}
            <div
                className="anim-fade-up pointer-events-none absolute inset-x-0 bottom-6 z-20 hidden px-10 md:flex md:items-end md:justify-between"
                style={{ animationDelay: "2.2s" }}
            >
                <div className="flex items-end gap-5">
                    <div className="relative h-14 w-px overflow-hidden bg-champagne/30">
                        <span className="anim-scroll-dot absolute left-0 top-0 h-1/3 w-px bg-espresso" />
                    </div>
                    <span className="eyebrow text-[0.6rem] text-muted-foreground [writing-mode:vertical-rl] rotate-180">
                        Cuộn xuống
                    </span>
                </div>
                <p className="eyebrow text-[0.6rem] text-muted-foreground">
                    <span className="text-espresso">01</span> / 05
                </p>
            </div>
        </section>
    );
}
