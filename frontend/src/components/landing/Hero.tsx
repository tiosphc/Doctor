import { HeroVisual } from "./HeroVisual";
import { LuxButton } from "./LuxButton";
import "./Hero.css";

export function Hero() {
    return (
        <section id="trang-chu" className="junie-hero grain" aria-labelledby="hero-heading">
            <div className="junie-hero__foliage" aria-hidden="true" />
            <div className="junie-hero__layout">
                <div className="junie-hero__copy">
                    <p className="junie-hero__eyebrow eyebrow">
                        <span aria-hidden="true" />
                        Junie Beauty Clinic
                    </p>
                    <h1 id="hero-heading" className="junie-hero__heading font-display">
                        <span>Nâng niu</span>
                        <span className="junie-hero__accent">
                            <em>vẻ đẹp</em>
                            <svg viewBox="0 0 220 72" fill="none" aria-hidden="true">
                                <path d="M3 65C79 24 189-18 216 19" />
                            </svg>
                        </span>
                        <span>chỉ có ở bạn</span>
                    </h1>
                    <p className="junie-hero__description">
                        Chăm sóc làn da, tôn vinh nét riêng và đồng hành cùng bạn trên hành trình
                        đẹp hơn mỗi ngày.
                    </p>
                    <div className="junie-hero__actions">
                        <LuxButton href="/booking" className="junie-hero__cta">
                            Đặt lịch tư vấn
                        </LuxButton>
                        <LuxButton href="#dich-vu" variant="line" className="junie-hero__cta">
                            Khám phá dịch vụ
                        </LuxButton>
                    </div>
                </div>
                <HeroVisual />
            </div>
            <div className="junie-hero__scroll" aria-hidden="true">
                <span>Cuộn xuống</span>
                <span className="junie-hero__scroll-line" />
                <span className="junie-hero__scroll-mouse" />
            </div>
        </section>
    );
}
