import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import {
    ArrowRight,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Sparkles,
} from "lucide-react";
import { Link, useNavigate } from "@tanstack/react-router";
import { ButtonLink } from "@/components/common/Button";
import { Container } from "@/components/common/Container";
import treatment from "@/assets/treatment.jpg";
import type { Service, ServiceCategoryExplorer } from "@/types";

export function categoryPath(categorySlug: string): string {
    return `/services/${categorySlug}`;
}

export function servicePath(service: Service): string {
    if (service.category_slug && service.slug) {
        return `/services/${service.category_slug}/${service.slug}`;
    }
    return "/services";
}

export function categoryHeroImage(category: ServiceCategoryExplorer): string {
    return (
        category.hero_image ||
        category.services.find((service) => service.hero_image || service.image)?.hero_image ||
        category.services.find((service) => service.image)?.image ||
        treatment
    );
}

function serviceHeroImage(service: Service): string {
    return service.hero_image || service.image || treatment;
}

function servicePriceLabel(service: Service): string {
    return `${new Intl.NumberFormat("vi-VN").format(Number(service.price))} VNĐ`;
}

type CenteredCardRailProps<T> = {
    items: T[];
    activeId: string;
    getId: (item: T) => string;
    onSelect: (item: T) => void;
    renderCard: (item: T, active: boolean) => ReactNode;
    ariaLabel: string;
};

function CenteredCardRail<T>({
    items,
    activeId,
    getId,
    onSelect,
    renderCard,
    ariaLabel,
}: CenteredCardRailProps<T>) {
    const railRef = useRef<HTMLDivElement | null>(null);
    const itemRefs = useRef<Record<string, HTMLButtonElement | null>>({});
    const activeIndex = Math.max(
        0,
        items.findIndex((item) => getId(item) === activeId),
    );

    useEffect(() => {
        const activeElement = itemRefs.current[activeId];
        const rail = railRef.current;
        if (!activeElement || !rail) return;

        const frame = window.requestAnimationFrame(() => {
            const left =
                activeElement.offsetLeft - (rail.clientWidth - activeElement.offsetWidth) / 2;
            const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
            rail.scrollTo({ left: Math.max(0, left), behavior: reducedMotion ? "auto" : "smooth" });
        });

        return () => window.cancelAnimationFrame(frame);
    }, [activeId, items]);

    function handleKeyDown(event: React.KeyboardEvent<HTMLDivElement>): void {
        if (items.length < 2) return;
        let nextIndex = activeIndex;
        if (event.key === "ArrowRight") nextIndex = (activeIndex + 1) % items.length;
        if (event.key === "ArrowLeft") nextIndex = (activeIndex - 1 + items.length) % items.length;
        if (event.key === "Home") nextIndex = 0;
        if (event.key === "End") nextIndex = items.length - 1;
        if (nextIndex === activeIndex) return;
        event.preventDefault();
        const nextItem = items[nextIndex];
        if (nextItem) onSelect(nextItem);
    }

    if (items.length === 0) return null;

    return (
        <div
            ref={railRef}
            role="listbox"
            tabIndex={0}
            aria-label={ariaLabel}
            aria-activedescendant={`${ariaLabel.replace(/\s+/g, "-")}-${activeId}`}
            onKeyDown={handleKeyDown}
            className="scrollbar-none flex w-full min-w-0 max-w-full snap-x snap-mandatory gap-3 overflow-x-auto pb-3 pt-2 outline-none focus-visible:ring-2 focus-visible:ring-secondary focus-visible:ring-offset-2 focus-visible:ring-offset-transparent"
            style={{
                paddingLeft: "max(1rem, calc(50% - clamp(15rem, 65vw, 20rem) / 2))",
                paddingRight: "max(1rem, calc(50% - clamp(15rem, 65vw, 20rem) / 2))",
            }}
        >
            {items.map((item) => {
                const id = getId(item);
                const active = id === activeId;
                return (
                    <button
                        key={id}
                        id={`${ariaLabel.replace(/\s+/g, "-")}-${id}`}
                        ref={(element) => {
                            itemRefs.current[id] = element;
                        }}
                        type="button"
                        role="option"
                        aria-selected={active}
                        onClick={() => onSelect(item)}
                        className={`group relative w-[clamp(15rem,65vw,20rem)] shrink-0 snap-center overflow-hidden rounded-2xl border text-left shadow-2xl transition-[transform,opacity,filter,border-color] duration-500 motion-reduce:transition-none ${active ? "z-10 scale-100 border-secondary shadow-secondary/20" : "scale-[.84] border-white/20 opacity-70 grayscale-[.2] hover:scale-[.9] hover:opacity-100 hover:grayscale-0"}`}
                    >
                        {renderCard(item, active)}
                    </button>
                );
            })}
        </div>
    );
}

function RailControls({
    current,
    total,
    onPrevious,
    onNext,
    label,
}: {
    current: number;
    total: number;
    onPrevious: () => void;
    onNext: () => void;
    label: string;
}) {
    return (
        <div className="mb-3 flex items-center justify-between gap-4 border-t border-white/20 pt-4 text-primary-foreground/70">
            <span className="text-xs uppercase tracking-[.18em]">
                {String(current + 1).padStart(2, "0")} / {String(total).padStart(2, "0")}
            </span>
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    aria-label={`${label} trước`}
                    onClick={onPrevious}
                    className="focus-premium grid size-9 place-items-center rounded-full border border-white/35 transition hover:border-secondary hover:bg-white/10"
                >
                    <ChevronLeft size={17} aria-hidden="true" />
                </button>
                <button
                    type="button"
                    aria-label={`${label} tiếp theo`}
                    onClick={onNext}
                    className="focus-premium grid size-9 place-items-center rounded-full border border-white/35 transition hover:border-secondary hover:bg-white/10"
                >
                    <ChevronRight size={17} aria-hidden="true" />
                </button>
            </div>
        </div>
    );
}

function CategoryRailCard({
    category,
    active,
}: {
    category: ServiceCategoryExplorer;
    active: boolean;
}) {
    return (
        <div className="relative aspect-[1.18] min-h-40 w-full overflow-hidden bg-navy-deep sm:min-h-44">
            <img
                src={categoryHeroImage(category)}
                alt=""
                loading={active ? "eager" : "lazy"}
                className="absolute inset-0 size-full object-cover transition duration-700 group-hover:scale-105 motion-reduce:transition-none"
            />
            <div className="absolute inset-0 bg-gradient-to-t from-navy-deep via-navy-deep/30 to-transparent" />
            <div className="absolute inset-x-0 bottom-0 p-4 text-primary-foreground sm:p-5">
                <p className="text-[10px] uppercase tracking-[.16em] text-secondary">
                    {category.services_count} dịch vụ
                </p>
                <h2 className="mt-1 font-display text-xl leading-tight sm:text-2xl">
                    {category.name}
                </h2>
                <span className="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-primary-foreground/80">
                    {active ? "Bấm để khám phá" : "Chọn danh mục"}{" "}
                    <ArrowRight size={13} aria-hidden="true" />
                </span>
            </div>
        </div>
    );
}

function ServiceRailCard({ service, active }: { service: Service; active: boolean }) {
    const price = servicePriceLabel(service);

    return (
        <div className="relative aspect-[1.18] min-h-40 w-full overflow-hidden bg-navy-deep sm:min-h-44">
            <img
                src={serviceHeroImage(service)}
                alt=""
                loading={active ? "eager" : "lazy"}
                className="absolute inset-0 size-full object-cover transition duration-700 group-hover:scale-105 motion-reduce:transition-none"
            />
            <div className="absolute inset-0 bg-gradient-to-t from-navy-deep via-navy-deep/30 to-transparent" />
            <div className="absolute inset-x-0 bottom-0 p-4 text-primary-foreground sm:p-5">
                <p className="text-[10px] uppercase tracking-[.16em] text-secondary">
                    {service.duration} phút tư vấn
                </p>
                <h2 className="mt-1 font-display text-xl leading-tight sm:text-2xl">
                    {service.name}
                </h2>
                <p className="ml-auto mt-2 w-fit rounded-full border border-secondary/70 bg-secondary px-3 py-1 text-xs font-bold text-primary shadow-lg shadow-black/15">
                    {price}
                </p>
                <span className="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-primary-foreground/80">
                    {active ? "Bấm để xem chi tiết" : "Chọn dịch vụ"}{" "}
                    <ArrowRight size={13} aria-hidden="true" />
                </span>
            </div>
        </div>
    );
}

export function ServiceExplorer({ categories }: { categories: ServiceCategoryExplorer[] }) {
    const navigate = useNavigate();
    const [activeSlug, setActiveSlug] = useState<string | null>(categories[0]?.slug ?? null);
    const activeIndex = Math.max(
        0,
        categories.findIndex((category) => category.slug === activeSlug),
    );
    const activeCategory = categories[activeIndex] ?? categories[0];

    useEffect(() => {
        if (!categories.some((category) => category.slug === activeSlug)) {
            setActiveSlug(categories[0]?.slug ?? null);
        }
    }, [activeSlug, categories]);

    if (!activeCategory) return null;
    const selectedCategory = activeCategory;

    function selectCategory(category: ServiceCategoryExplorer): void {
        if (category.slug === selectedCategory.slug) {
            void navigate({ to: categoryPath(category.slug) });
            return;
        }
        setActiveSlug(category.slug);
    }

    function changeCategory(index: number): void {
        const nextCategory = categories[(index + categories.length) % categories.length];
        if (nextCategory) setActiveSlug(nextCategory.slug);
    }

    return (
        <>
            <section className="relative isolate overflow-hidden bg-[#071923] text-primary-foreground">
                <div className="absolute inset-0 -z-10" aria-hidden="true">
                    {categories.map((category) => (
                        <img
                            key={category.id}
                            src={categoryHeroImage(category)}
                            alt=""
                            className={`absolute inset-0 size-full object-cover transition-opacity duration-700 motion-reduce:transition-none ${category.slug === activeCategory.slug ? "opacity-100" : "opacity-0"}`}
                        />
                    ))}
                    <div className="absolute inset-0 bg-gradient-to-r from-[#06121b]/95 via-[#071923]/65 to-[#071923]/35" />
                    <div className="absolute inset-0 bg-gradient-to-t from-[#071923] via-transparent to-black/20" />
                </div>
                <Container className="relative flex min-h-[min(900px,100svh)] w-full min-w-0 flex-col justify-between pb-8 pt-28 md:pb-10 md:pt-32">
                    <div
                        key={activeCategory.slug}
                        className="max-w-2xl motion-safe:animate-[fade-in_.5s_ease-out]"
                    >
                        <div className="flex items-center gap-3 text-xs uppercase tracking-[.2em] text-secondary">
                            <Sparkles size={16} aria-hidden="true" />
                            <span>Viện da liễu & thẩm mỹ y khoa</span>
                        </div>
                        <p className="mt-8 text-sm text-primary-foreground/70">
                            {activeCategory.services_count} dịch vụ trong danh mục
                        </p>
                        <h1 className="mt-3 max-w-xl font-display text-4xl leading-[1.04] md:text-6xl lg:text-7xl">
                            {activeCategory.name}
                        </h1>
                        <p className="mt-5 max-w-xl text-sm leading-7 text-primary-foreground/80 md:text-base">
                            {activeCategory.short_description ||
                                "Khám phá những lựa chọn được thiết kế để bạn có thêm thông tin trước buổi tư vấn cùng đội ngũ Junie."}
                        </p>
                        <div className="mt-7 flex flex-wrap gap-3">
                            <ButtonLink
                                to={categoryPath(activeCategory.slug)}
                                className="border-secondary bg-secondary text-primary hover:bg-secondary/85"
                            >
                                Khám phá danh mục <ArrowRight size={16} aria-hidden="true" />
                            </ButtonLink>
                            <ButtonLink
                                to="/booking"
                                variant="outline"
                                className="border-primary-foreground/55 text-primary-foreground hover:bg-primary-foreground/10"
                            >
                                <CalendarDays size={16} aria-hidden="true" /> Đặt lịch tư vấn 1:1
                            </ButtonLink>
                        </div>
                    </div>
                    <div className="mt-14 min-w-0">
                        <RailControls
                            current={activeIndex}
                            total={categories.length}
                            label="Danh mục"
                            onPrevious={() => changeCategory(activeIndex - 1)}
                            onNext={() => changeCategory(activeIndex + 1)}
                        />
                        <CenteredCardRail
                            items={categories}
                            activeId={activeCategory.slug}
                            getId={(category) => category.slug}
                            onSelect={selectCategory}
                            ariaLabel="Danh mục dịch vụ"
                            renderCard={(category, active) => (
                                <CategoryRailCard category={category} active={active} />
                            )}
                        />
                    </div>
                </Container>
            </section>
        </>
    );
}

export function CategoryServicePresentation({ category }: { category: ServiceCategoryExplorer }) {
    const navigate = useNavigate();
    const [activeSlug, setActiveSlug] = useState<string | null>(category.services[0]?.slug ?? null);
    const activeIndex = Math.max(
        0,
        category.services.findIndex((service) => service.slug === activeSlug),
    );
    const activeService = category.services[activeIndex] ?? category.services[0];

    useEffect(() => {
        if (!category.services.some((service) => service.slug === activeSlug)) {
            setActiveSlug(category.services[0]?.slug ?? null);
        }
    }, [activeSlug, category.services]);

    if (!activeService) {
        return (
            <Container className="section-space">
                <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                    Danh mục này chưa có dịch vụ đang hoạt động.
                </p>
            </Container>
        );
    }
    const selectedService = activeService;
    const activeServicePrice = servicePriceLabel(activeService);

    function selectService(service: Service): void {
        if (service.slug === selectedService.slug) {
            void navigate({ to: servicePath(service) });
            return;
        }
        setActiveSlug(service.slug);
    }

    function changeService(index: number): void {
        const nextService =
            category.services[(index + category.services.length) % category.services.length];
        if (nextService) setActiveSlug(nextService.slug);
    }

    return (
        <section className="relative isolate overflow-hidden bg-[#071923] text-primary-foreground">
            <div className="absolute inset-0 -z-10" aria-hidden="true">
                <img
                    src={serviceHeroImage(activeService)}
                    alt=""
                    className="size-full object-cover opacity-70 transition-opacity duration-700 motion-reduce:transition-none"
                />
                <div className="absolute inset-0 bg-gradient-to-r from-[#06121b]/95 via-[#071923]/70 to-[#071923]/35" />
                <div className="absolute inset-0 bg-gradient-to-t from-[#071923] via-transparent to-black/15" />
            </div>
            <Container className="relative flex min-h-[min(900px,100svh)] w-full min-w-0 flex-col justify-between pb-8 pt-28 md:pb-10 md:pt-32">
                <div className="max-w-2xl">
                    <Link
                        to="/services"
                        className="focus-premium inline-flex items-center gap-2 text-sm text-primary-foreground/75 transition hover:text-primary-foreground"
                    >
                        <ChevronLeft size={16} aria-hidden="true" /> Tất cả danh mục
                    </Link>
                    <div
                        key={activeService.slug}
                        className="mt-10 motion-safe:animate-[fade-in_.5s_ease-out]"
                    >
                        <p className="flex items-center gap-2 text-xs uppercase tracking-[.2em] text-secondary">
                            <Sparkles size={15} aria-hidden="true" /> {category.name}
                        </p>
                        <div className="mt-6 flex w-full max-w-xl flex-wrap items-center justify-between gap-3 text-sm">
                            <span className="text-primary-foreground/70">
                                {activeService.duration} phút tư vấn chuyên sâu
                            </span>
                            <span className="ml-auto rounded-full border border-secondary/70 bg-secondary px-4 py-2 font-bold text-primary shadow-lg shadow-black/15">
                                {activeServicePrice}
                            </span>
                        </div>
                        <h1 className="mt-3 max-w-xl font-display text-4xl leading-[1.04] md:text-6xl lg:text-7xl">
                            {activeService.name}
                        </h1>
                        <p className="mt-5 max-w-xl text-sm leading-7 text-primary-foreground/80 md:text-base">
                            {activeService.short_description ||
                                activeService.description ||
                                "Tìm hiểu thêm về liệu trình và bắt đầu bằng một buổi tư vấn cùng Junie."}
                        </p>
                        <div className="mt-7 flex flex-wrap gap-3">
                            <ButtonLink
                                to={servicePath(activeService)}
                                className="border-secondary bg-secondary text-primary hover:bg-secondary/85"
                            >
                                Xem chi tiết <ArrowRight size={16} aria-hidden="true" />
                            </ButtonLink>
                            <ButtonLink
                                to={`/booking?service_id=${activeService.id}`}
                                variant="outline"
                                className="border-primary-foreground/55 text-primary-foreground hover:bg-primary-foreground/10"
                            >
                                <CalendarDays size={16} aria-hidden="true" /> Đặt lịch tư vấn
                            </ButtonLink>
                        </div>
                    </div>
                </div>
                <div className="mt-14 min-w-0">
                    <RailControls
                        current={activeIndex}
                        total={category.services.length}
                        label="Dịch vụ"
                        onPrevious={() => changeService(activeIndex - 1)}
                        onNext={() => changeService(activeIndex + 1)}
                    />
                    <CenteredCardRail
                        items={category.services}
                        activeId={activeService.slug}
                        getId={(service) => service.slug}
                        onSelect={selectService}
                        ariaLabel={`Dịch vụ trong ${category.name}`}
                        renderCard={(service, active) => (
                            <ServiceRailCard service={service} active={active} />
                        )}
                    />
                </div>
            </Container>
        </section>
    );
}

type Concern = { label: string; terms: string[] };

const concerns: Concern[] = [
    { label: "Da chảy xệ", terms: ["chảy xệ", "nâng cơ", "săn chắc", "lão hóa"] },
    { label: "Nếp nhăn", terms: ["nếp nhăn", "wrinkle", "fine line", "lão hóa"] },
    { label: "Nọng cằm", terms: ["nọng cằm", "cằm", "jawline", "contour"] },
    { label: "Mụn", terms: ["mụn", "acne", "bít tắc", "breakout"] },
    { label: "Nám", terms: ["nám", "melasma", "sạm", "đốm nâu"] },
    { label: "Lỗ chân lông", terms: ["lỗ chân lông", "pores", "se khít"] },
    { label: "Da thiếu sức sống", terms: ["thiếu sức sống", "xỉn", "dull", "glow", "tươi sáng"] },
    { label: "Sẹo mụn", terms: ["sẹo mụn", "sẹo", "scar", "thâm mụn"] },
    { label: "Không đều màu", terms: ["không đều màu", "đều màu", "tone", "sáng"] },
];

export function ConcernSelector({ categories }: { categories: ServiceCategoryExplorer[] }) {
    const [selectedLabel, setSelectedLabel] = useState<string | null>(null);
    const selectedConcern = concerns.find((concern) => concern.label === selectedLabel);
    const matchingServices = useMemo(() => {
        if (!selectedConcern) return [];
        const terms = selectedConcern.terms.map((term) => term.toLocaleLowerCase("vi"));
        return categories.flatMap((category) =>
            category.services
                .filter((service) => {
                    const searchable = [
                        service.name,
                        service.description ?? "",
                        service.category ?? "",
                        category.name,
                        category.short_description ?? "",
                    ]
                        .join(" ")
                        .toLocaleLowerCase("vi");
                    return terms.some((term) => searchable.includes(term));
                })
                .map((service) => ({ service, category })),
        );
    }, [categories, selectedConcern]);
    const firstMatchingCategory = matchingServices[0]?.category;

    return (
        <section className="section-space bg-background">
            <Container>
                <div className="max-w-2xl">
                    <p className="label-luxury">Khám phá nhẹ nhàng</p>
                    <h2 className="mt-3 text-3xl font-semibold text-primary md:text-5xl">
                        Bạn muốn cải thiện điều gì?
                    </h2>
                    <p className="mt-4 text-sm leading-6 text-muted-foreground md:text-base">
                        Chọn một chủ đề để xem gợi ý từ các dịch vụ đang có. Đây chỉ là điểm bắt đầu
                        tham khảo, không thay thế cho thăm khám hoặc tư vấn chuyên môn.
                    </p>
                </div>
                <div className="mt-7 flex flex-wrap gap-3">
                    {concerns.map((concern) => {
                        const active = selectedLabel === concern.label;
                        return (
                            <button
                                key={concern.label}
                                type="button"
                                aria-pressed={active}
                                onClick={() => setSelectedLabel(active ? null : concern.label)}
                                className={`focus-premium rounded-full border px-4 py-2.5 text-sm transition ${active ? "border-primary bg-primary text-primary-foreground" : "border-border bg-card text-primary hover:border-primary"}`}
                            >
                                {concern.label}
                            </button>
                        );
                    })}
                </div>
                {selectedConcern && (
                    <div className="mt-10">
                        {matchingServices.length === 0 ? (
                            <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                                Hiện chưa có dịch vụ phù hợp với lựa chọn này. Bạn có thể xem toàn
                                bộ danh mục hoặc đặt lịch để được tư vấn thêm.
                            </p>
                        ) : (
                            <>
                                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                                    {matchingServices.slice(0, 3).map(({ service, category }) => (
                                        <ServiceExplorerCard
                                            key={`${category.id}-${service.id}`}
                                            service={service}
                                            category={category}
                                        />
                                    ))}
                                </div>
                                {firstMatchingCategory && (
                                    <div className="mt-6 flex justify-end">
                                        <Link
                                            to={categoryPath(firstMatchingCategory.slug)}
                                            className="focus-premium inline-flex items-center gap-2 text-sm font-semibold text-primary transition hover:text-secondary"
                                        >
                                            Xem các giải pháp <span aria-hidden="true">→</span>
                                        </Link>
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                )}
            </Container>
        </section>
    );
}

export function ServiceExplorerCard({
    service,
    category,
    featured = false,
}: {
    service: Service;
    category?: ServiceCategoryExplorer | undefined;
    featured?: boolean;
}) {
    return (
        <article
            className={`group relative overflow-hidden rounded-xl border border-border bg-card shadow-card ${featured ? "min-h-[28rem]" : ""}`}
        >
            <Link to={servicePath(service)} className="focus-premium block h-full">
                <div
                    className={`${featured ? "aspect-[4/3] h-full" : "aspect-[16/10]"} overflow-hidden`}
                >
                    <img
                        src={serviceHeroImage(service)}
                        alt={service.name}
                        loading="lazy"
                        className="size-full object-cover transition duration-700 group-hover:scale-105 motion-reduce:transition-none"
                    />
                </div>
                <div
                    className={
                        featured
                            ? "absolute inset-x-0 bottom-0 bg-gradient-to-t from-navy-deep via-navy-deep/80 to-transparent p-6 pt-20 text-primary-foreground"
                            : "p-5"
                    }
                >
                    <p className="text-[11px] uppercase tracking-wider text-secondary">
                        {service.category || category?.name || "Liệu trình y khoa"}
                    </p>
                    <h3
                        className={`mt-2 text-2xl ${featured ? "text-primary-foreground" : "text-primary"}`}
                    >
                        {service.name}
                    </h3>
                    <p
                        className={`mt-2 line-clamp-2 text-sm leading-6 ${featured ? "text-primary-foreground/75" : "text-muted-foreground"}`}
                    >
                        {service.description ||
                            "Thông tin tham khảo để bạn chuẩn bị cho buổi tư vấn cùng bác sĩ."}
                    </p>
                    <div
                        className={`mt-4 flex items-center justify-between gap-3 text-xs ${featured ? "text-primary-foreground/75" : "text-muted-foreground"}`}
                    >
                        <span className="flex items-center gap-1.5">
                            <Clock3 size={14} aria-hidden="true" /> {service.duration} phút
                        </span>
                        <span className="inline-flex items-center gap-1 font-semibold text-secondary">
                            Xem chi tiết <ArrowRight size={14} aria-hidden="true" />
                        </span>
                    </div>
                </div>
            </Link>
        </article>
    );
}

export function BookingCTA({
    serviceId,
    title = "Bạn đã sẵn sàng bắt đầu?",
}: {
    serviceId?: number | undefined;
    title?: string;
}) {
    return (
        <section className="overflow-hidden rounded-2xl bg-navy-deep px-6 py-10 text-primary-foreground shadow-card md:flex md:items-center md:justify-between md:gap-8 md:px-10">
            <div>
                <p className="label-luxury text-secondary">Đồng hành cùng Junie</p>
                <h2 className="mt-3 text-3xl text-primary-foreground md:text-4xl">{title}</h2>
                <p className="mt-3 max-w-xl text-sm leading-6 text-primary-foreground/70">
                    Đặt lịch để trao đổi cùng đội ngũ chuyên môn. Mọi lựa chọn sẽ được cân nhắc theo
                    tình trạng thực tế và mong muốn của bạn.
                </p>
            </div>
            <ButtonLink
                to={serviceId ? `/booking?service_id=${serviceId}` : "/booking"}
                className="mt-6 shrink-0 border-secondary bg-secondary text-primary hover:bg-secondary/85 md:mt-0"
            >
                <CalendarDays size={16} aria-hidden="true" /> Đặt lịch tư vấn
            </ButtonLink>
        </section>
    );
}
