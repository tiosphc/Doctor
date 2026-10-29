import { useEffect, useRef, useState } from "react";
import * as Dialog from "@radix-ui/react-dialog";
import { ArrowUpRight, UserRound, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { BrandLogo } from "@/components/common/BrandLogo";
import { useAuth } from "@/contexts/AuthContext";
import { useScrollY } from "@/hooks/use-lux-motion";
import "./Nav.css";

const links = [
    { href: "/#trang-chu", label: "Trang chủ" },
    { href: "/#ve-junie", label: "Về Junie" },
    { href: "/#dich-vu", label: "Dịch vụ" },
    { href: "/#khong-gian", label: "Không gian" },
    { href: "/#lien-he", label: "Liên hệ" },
];

export function Nav() {
    const y = useScrollY();
    const [open, setOpen] = useState(false);
    const { user } = useAuth();
    const [hidden, setHidden] = useState(false);
    const lastY = useRef(0);
    const solid = y > 40;

    useEffect(() => {
        const delta = y - lastY.current;
        lastY.current = y;
        if (y < 80) {
            setHidden(false);
            return;
        }
        if (delta > 4) setHidden(true);
        else if (delta < -4) setHidden(false);
    }, [y]);

    return (
        <header
            className={`junie-nav fixed inset-x-0 top-0 z-50 ${
                solid ? "bg-ivory/80 py-3 backdrop-blur-md border-b border-border" : "py-6"
            } ${hidden ? "junie-nav--hidden" : ""}`}
        >
            <nav className="junie-nav__inner mx-auto grid max-w-[1600px] grid-cols-[minmax(0,1fr)_auto] items-center gap-6 px-6 md:px-10 lg:grid-cols-[1fr_auto_1fr]">
                <a href="/" className="junie-nav__brand min-w-0" aria-label="Junie — Trang chủ">
                    <BrandLogo />
                </a>
                <ul className="junie-nav__links hidden items-center gap-9 lg:flex">
                    {links.map((l) => (
                        <li key={l.href}>
                            <a
                                href={l.href}
                                className="link-lux text-[0.8rem] tracking-wide text-espresso/80 hover:text-espresso"
                            >
                                {l.label}
                            </a>
                        </li>
                    ))}
                </ul>
                <div className="junie-nav__actions flex shrink-0 items-center justify-end gap-6">
                    <a href="/booking" className="eyebrow link-lux hidden sm:inline">
                        Đặt lịch
                    </a>
                    <Button asChild variant="ghost" className="junie-nav__login">
                        <a href={user ? "/account" : "/login"}>
                            <UserRound strokeWidth={1.3} />
                            {user ? "Tài khoản" : "Đăng nhập"}
                        </a>
                    </Button>
                    <Button
                        variant="ghost"
                        type="button"
                        onClick={() => setOpen((o) => !o)}
                        aria-expanded={open}
                        aria-label="Mở menu"
                        className="flex h-11 w-11 shrink-0 flex-col items-center justify-center gap-[6px] lg:hidden"
                    >
                        <span
                            className={`h-px w-6 bg-espresso transition-transform duration-500 ${open ? "translate-y-[3.5px] rotate-45" : ""}`}
                        />
                        <span
                            className={`h-px w-6 bg-espresso transition-transform duration-500 ${open ? "-translate-y-[3.5px] -rotate-45" : ""}`}
                        />
                    </Button>
                </div>
            </nav>

            <Dialog.Root open={open} onOpenChange={setOpen}>
                <Dialog.Portal>
                    <Dialog.Content className="junie-menu grain" aria-describedby={undefined}>
                        <div className="junie-menu__top">
                            <a href="/" onClick={() => setOpen(false)} className="text-xl">
                                <BrandLogo />
                            </a>
                            <Dialog.Close asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="junie-menu__close"
                                    aria-label="Đóng menu"
                                >
                                    <X strokeWidth={1.2} />
                                </Button>
                            </Dialog.Close>
                        </div>
                        <div className="junie-menu__body">
                            <Dialog.Title className="junie-menu__title">
                                Khám phá Junie
                            </Dialog.Title>
                            <nav aria-label="Điều hướng mobile">
                                <ul>
                                    {links.map((l, i) => (
                                        <li key={l.href}>
                                            <a
                                                href={l.href}
                                                onClick={() => setOpen(false)}
                                                className="junie-menu__link"
                                            >
                                                <span className="junie-menu__number">0{i + 1}</span>
                                                <span>{l.label}</span>
                                                <ArrowUpRight strokeWidth={1.2} />
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </nav>
                        </div>
                        <div className="junie-menu__footer">
                            <Button asChild className="junie-menu__booking">
                                <a href="/booking" onClick={() => setOpen(false)}>
                                    Đặt lịch tư vấn <ArrowUpRight strokeWidth={1.2} />
                                </a>
                            </Button>
                            <Button asChild variant="ghost" className="junie-menu__login">
                                <a
                                    href={user ? "/account" : "/login"}
                                    onClick={() => setOpen(false)}
                                >
                                    <UserRound strokeWidth={1.3} />
                                    {user ? "Tài khoản" : "Đăng nhập"}
                                </a>
                            </Button>
                        </div>
                    </Dialog.Content>
                </Dialog.Portal>
            </Dialog.Root>
        </header>
    );
}
