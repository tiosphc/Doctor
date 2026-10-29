import { BrandLogo } from "@/components/common/BrandLogo";
import { useAuth } from "@/contexts/AuthContext";
import "./LandingTheme.css";

export function LovableFlowLayout({ children }: { children: React.ReactNode }) {
    const { user } = useAuth();

    return (
        <div className="lovable-home lovable-flow min-h-screen bg-cream">
            <header className="border-b border-border bg-ivory">
                <nav className="mx-auto flex max-w-[1400px] flex-wrap items-center justify-between gap-4 px-6 py-5 md:px-10">
                    <a
                        href="/"
                        className="text-xl text-espresso md:text-2xl"
                        aria-label="Junie — Trang chủ"
                    >
                        <BrandLogo />
                    </a>
                    <div className="flex items-center gap-5 text-xs text-espresso md:gap-8">
                        <a href="/services" className="link-lux">
                            Dịch vụ
                        </a>
                        <a href="/booking" className="link-lux">
                            Đặt lịch
                        </a>
                        <a href={user ? "/account" : "/login"} className="link-lux">
                            Tài khoản
                        </a>
                    </div>
                </nav>
            </header>
            <main>{children}</main>
            <footer className="border-t border-border bg-ivory px-6 py-8 text-center text-xs text-mocha">
                © {new Date().getFullYear()} Junie
            </footer>
        </div>
    );
}
