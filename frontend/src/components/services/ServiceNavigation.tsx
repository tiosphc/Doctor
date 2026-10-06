import { useState } from "react";
import { Link } from "@tanstack/react-router";
import { CalendarDays, ChevronDown, ChevronRight } from "lucide-react";
import { LoadingState } from "@/components/common/AsyncState";
import { categoryPath, servicePath } from "./ServiceExplorer";
import { useServiceCategories } from "./serviceQueries";
import type { ServiceCategoryExplorer } from "@/types";

export function ServiceMegaMenu({ inverted = false }: { inverted?: boolean }) {
    const categories = useServiceCategories();

    return (
        <div className="group relative">
            <Link
                to="/services"
                activeOptions={{ exact: false }}
                className={`focus-premium inline-flex items-center gap-1 border-b-2 border-transparent py-2 text-sm transition-colors ${inverted ? "text-white/80 group-hover:border-white/60 group-hover:text-white group-focus-within:border-white/60 group-focus-within:text-white" : "text-foreground/80 group-hover:border-primary group-hover:text-primary group-focus-within:border-primary group-focus-within:text-primary"}`}
                activeProps={{
                    className: inverted
                        ? "border-white font-semibold text-white"
                        : "border-primary font-semibold text-primary",
                }}
                aria-haspopup="true"
            >
                Dịch vụ
                <ChevronDown size={14} aria-hidden="true" />
            </Link>
            <div className="pointer-events-none invisible absolute left-1/2 top-full z-50 w-[min(92vw,1240px)] -translate-x-1/2 -translate-y-2 pt-5 opacity-0 transition duration-200 ease-out group-hover:pointer-events-auto group-hover:visible group-hover:translate-y-0 group-hover:opacity-100 group-focus-within:pointer-events-auto group-focus-within:visible group-focus-within:translate-y-0 group-focus-within:opacity-100 motion-reduce:transition-none">
                <div className="rounded-b-xl border bg-card p-6 text-foreground shadow-2xl md:p-8">
                    <div className="mb-6 flex items-end justify-between gap-4 border-b pb-5">
                        <div>
                            <p className="label-luxury">Khám phá liệu trình</p>
                            <p className="mt-2 font-display text-2xl text-primary">
                                Chăm sóc theo nhu cầu của bạn
                            </p>
                        </div>
                        <div className="hidden shrink-0 items-center gap-6 sm:flex">
                            <Link
                                to="/booking"
                                className="focus-premium inline-flex items-center gap-2 text-sm font-semibold text-primary hover:text-secondary"
                            >
                                <CalendarDays size={15} aria-hidden="true" />
                                Đặt lịch tư vấn
                            </Link>
                            <Link
                                to="/services"
                                className="focus-premium inline-flex items-center gap-2 text-sm font-semibold text-primary"
                            >
                                Xem toàn bộ dịch vụ <ChevronRight size={15} aria-hidden="true" />
                            </Link>
                        </div>
                    </div>
                    {categories.isPending ? (
                        <LoadingState label="Đang tải danh mục..." />
                    ) : categories.isError ? (
                        <p className="text-sm text-red-700">Không thể tải danh mục dịch vụ.</p>
                    ) : categories.data.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Chưa có danh mục dịch vụ đang hoạt động.
                        </p>
                    ) : (
                        <div className="grid gap-7 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {categories.data.data.map((category) => (
                                <MegaMenuCategory key={category.id} category={category} />
                            ))}
                        </div>
                    )}
                    <Link
                        to="/services"
                        className="focus-premium mt-6 inline-flex items-center gap-2 border-t pt-5 text-sm font-semibold text-primary sm:hidden"
                    >
                        Xem toàn bộ dịch vụ <ChevronRight size={15} aria-hidden="true" />
                    </Link>
                </div>
            </div>
        </div>
    );
}

function MegaMenuCategory({ category }: { category: ServiceCategoryExplorer }) {
    return (
        <div className="min-w-0">
            <Link
                to={categoryPath(category.slug)}
                className="focus-premium block border-b-2 border-secondary/60 pb-3 text-[20px] font-bold leading-snug tracking-[.01em] text-primary transition-colors hover:border-secondary hover:text-secondary"
            >
                {category.name}
            </Link>
            <ul className="mt-3 grid gap-2">
                {category.services.slice(0, 6).map((service) => (
                    <li key={service.id}>
                        <Link
                            to={servicePath(service)}
                            className="focus-premium block truncate text-[16px] font-normal text-muted-foreground transition-colors hover:font-semibold hover:text-primary"
                        >
                            {service.name}
                        </Link>
                    </li>
                ))}
                {category.services.length > 6 && (
                    <li>
                        <Link
                            to={categoryPath(category.slug)}
                            className="focus-premium text-xs font-semibold text-secondary"
                        >
                            Xem thêm dịch vụ
                        </Link>
                    </li>
                )}
            </ul>
        </div>
    );
}

export function MobileServiceMenu({ onNavigate }: { onNavigate: () => void }) {
    const categories = useServiceCategories();
    const [expanded, setExpanded] = useState(false);
    const [openCategories, setOpenCategories] = useState<Record<string, boolean>>({});

    function toggleCategory(slug: string): void {
        setOpenCategories((current) => ({ ...current, [slug]: !current[slug] }));
    }

    return (
        <section className="border-b">
            <div className="flex items-center">
                <Link
                    to="/services"
                    onClick={onNavigate}
                    activeOptions={{ exact: false }}
                    className="focus-premium flex-1 py-4 font-medium text-primary"
                    activeProps={{ className: "font-semibold text-primary" }}
                >
                    Dịch vụ
                </Link>
                <button
                    type="button"
                    aria-expanded={expanded}
                    aria-controls="mobile-service-categories"
                    aria-label={expanded ? "Thu gọn danh mục dịch vụ" : "Mở danh mục dịch vụ"}
                    onClick={() => setExpanded((current) => !current)}
                    className="focus-premium grid size-10 place-items-center rounded-full text-primary"
                >
                    <ChevronDown
                        size={18}
                        aria-hidden="true"
                        className={`transition-transform ${expanded ? "rotate-180" : ""}`}
                    />
                </button>
            </div>
            {expanded && (
                <div id="mobile-service-categories" className="grid gap-2 pb-4 pl-3">
                    <Link
                        to="/booking"
                        onClick={onNavigate}
                        className="focus-premium inline-flex items-center gap-2 py-2 text-sm font-semibold text-primary"
                    >
                        <CalendarDays size={16} aria-hidden="true" />
                        Đặt lịch tư vấn
                    </Link>
                    {categories.isPending ? (
                        <LoadingState label="Đang tải danh mục..." />
                    ) : categories.isError ? (
                        <p className="py-2 text-sm text-red-700">Không thể tải danh mục dịch vụ.</p>
                    ) : categories.data.data.length === 0 ? (
                        <p className="py-2 text-sm text-muted-foreground">
                            Chưa có danh mục dịch vụ đang hoạt động.
                        </p>
                    ) : (
                        categories.data.data.map((category) => {
                            const categoryOpen = openCategories[category.slug] ?? false;
                            const servicesId = `mobile-service-${category.slug}`;
                            return (
                                <div
                                    key={category.id}
                                    className="border-l border-secondary/40 pl-3"
                                >
                                    <div className="flex items-center gap-2">
                                        <Link
                                            to={categoryPath(category.slug)}
                                            onClick={onNavigate}
                                            className="focus-premium flex-1 py-2 text-sm font-semibold text-primary"
                                        >
                                            {category.name}
                                        </Link>
                                        {category.services.length > 0 && (
                                            <button
                                                type="button"
                                                aria-expanded={categoryOpen}
                                                aria-controls={servicesId}
                                                aria-label={`${categoryOpen ? "Thu gọn" : "Mở"} dịch vụ ${category.name}`}
                                                onClick={() => toggleCategory(category.slug)}
                                                className="focus-premium grid size-9 place-items-center rounded-full text-primary"
                                            >
                                                <ChevronDown
                                                    size={16}
                                                    aria-hidden="true"
                                                    className={`transition-transform ${categoryOpen ? "rotate-180" : ""}`}
                                                />
                                            </button>
                                        )}
                                    </div>
                                    {categoryOpen && (
                                        <ul id={servicesId} className="grid gap-1 pb-2">
                                            {category.services.map((service) => (
                                                <li key={service.id}>
                                                    <Link
                                                        to={servicePath(service)}
                                                        onClick={onNavigate}
                                                        className="focus-premium block py-1.5 text-sm text-muted-foreground"
                                                    >
                                                        {service.name}
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            );
                        })
                    )}
                </div>
            )}
        </section>
    );
}
