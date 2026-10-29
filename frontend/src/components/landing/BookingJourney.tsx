import { useEffect, useRef, useState } from "react";
import { Sparkles, CalendarDays, CalendarCheck2 } from "lucide-react";
import { LuxButton } from "./LuxButton";
import { SectionLabel } from "./SectionLabel";
import "./BookingJourney.css";

const steps = [
    {
        number: "01",
        title: "Khám phá vẻ đẹp của bạn",
        description: "Lựa chọn dịch vụ phù hợp với nhu cầu và mong muốn của bạn.",
        Icon: Sparkles,
    },
    {
        number: "02",
        title: "Chọn khoảnh khắc dành riêng",
        description: "Chọn ngày giờ thuận tiện và để lại thông tin liên hệ của bạn.",
        Icon: CalendarDays,
    },
    {
        number: "03",
        title: "Hoàn tất cuộc hẹn",
        description: "Kiểm tra lại thông tin và xác nhận yêu cầu đặt lịch của bạn.",
        Icon: CalendarCheck2,
    },
];

export function BookingJourney() {
    const ref = useRef<HTMLElement>(null);
    const [active, setActive] = useState<number | null>(null);
    useEffect(() => {
        const section = ref.current;
        if (!section) return;
        section.classList.add("is-ready");
        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add("is-in");
                    observer.unobserve(entry.target);
                });
            },
            { threshold: 0.16 },
        );
        observer.observe(section);
        section.querySelectorAll(".junie-journey__step").forEach((step) => observer.observe(step));
        return () => observer.disconnect();
    }, []);

    return (
        <section ref={ref} className="junie-journey" aria-labelledby="junie-journey-title">
            <div className="junie-journey__inner">
                <div className="junie-journey__divider" aria-hidden="true" />
                <SectionLabel label="Junie — Hành trình đặt lịch" />
                <div className="junie-journey__header">
                    <h2 id="junie-journey-title" className="junie-journey__title">
                        <span>
                            <span>Một cuộc hẹn,</span>
                        </span>
                        <span>
                            <span>
                                khởi đầu cho <em>vẻ đẹp riêng.</em>
                            </span>
                        </span>
                    </h2>
                    <p className="junie-journey__description">
                        Chỉ với ba bước đơn giản, bạn đã có thể bắt đầu hành trình chăm sóc sắc đẹp
                        cùng Junie.
                    </p>
                </div>
                <div className="junie-journey__timeline" data-active={active ?? ""}>
                    <svg
                        className="junie-journey__path"
                        viewBox="0 0 1200 150"
                        preserveAspectRatio="none"
                        aria-hidden="true"
                    >
                        <path
                            className="junie-journey__path-base"
                            pathLength={1}
                            d="M35 52 C210 4 265 116 435 100 S660 52 835 66 S1010 104 1160 38"
                        />
                        <path
                            className="junie-journey__highlight junie-journey__highlight--first"
                            d="M35 52 C210 4 265 116 435 100 S660 52 835 66"
                        />
                        <path
                            className="junie-journey__highlight junie-journey__highlight--last"
                            d="M435 100 C580 86 660 52 835 66 S1010 104 1160 38"
                        />
                    </svg>
                    <ol className="junie-journey__steps">
                        {steps.map(({ number, title, description, Icon }, i) => (
                            <li
                                key={number}
                                className="junie-journey__step"
                                onMouseEnter={() => setActive(i)}
                                onMouseLeave={() => setActive(null)}
                            >
                                <div className="junie-journey__step-top">
                                    <span className="junie-journey__number" aria-hidden="true">
                                        {number}
                                    </span>
                                    <Icon
                                        className="junie-journey__icon"
                                        size={26}
                                        strokeWidth={1.1}
                                        aria-hidden="true"
                                    />
                                </div>
                                <h3>{title}</h3>
                                <p>{description}</p>
                            </li>
                        ))}
                    </ol>
                </div>
                <div className="junie-journey__footer">
                    <LuxButton href="/booking" className="junie-journey__cta">
                        BẮT ĐẦU ĐẶT LỊCH
                    </LuxButton>
                </div>
            </div>
        </section>
    );
}
