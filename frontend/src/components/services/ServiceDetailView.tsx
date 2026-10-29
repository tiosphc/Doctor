import { Link } from "@tanstack/react-router";
import { CalendarDays, ChevronLeft, ChevronRight, Clock3, ShieldCheck } from "lucide-react";
import { Container } from "@/components/common/Container";
import { ButtonLink } from "@/components/common/Button";
import { BookingCTA, ServiceExplorerCard } from "@/components/services/ServiceExplorer";
import treatment from "@/assets/treatment.jpg";
import { money } from "@/components/cards/Cards";
import type {
    Service,
    ServiceCategoryExplorer,
    ServiceContent,
    ServiceContentBenefit,
    ServiceContentFaq,
    ServiceContentFaqItem,
    ServiceContentProcess,
    ServiceContentResultCase,
    ServiceContentResults,
    ServiceContentStep,
} from "@/types";

type ServiceDetailViewProps = {
    service: Service;
    category?: ServiceCategoryExplorer | null | undefined;
    preview?: boolean;
    relatedServices?: Service[];
};

const DEFAULT_RESULTS_DISCLAIMER = "Kết quả và trải nghiệm có thể khác nhau tùy từng trường hợp.";

function clean(value: unknown): string {
    return typeof value === "string" || typeof value === "number" ? String(value).trim() : "";
}

function record(value: unknown): Record<string, unknown> | null {
    return value && typeof value === "object" && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}

function records(value: unknown): Record<string, unknown>[] {
    return Array.isArray(value)
        ? value.filter((item): item is Record<string, unknown> => Boolean(record(item)))
        : [];
}

function valueOf(source: Record<string, unknown> | null, key: string): string {
    return clean(source?.[key]);
}

function normalizeBenefits(
    value: unknown,
    fallback?: ServiceContentBenefit[],
): ServiceContent["benefits"] {
    const source = record(value);
    if (!source && !fallback) return undefined;
    return {
        title: valueOf(source, "title") || "Tác dụng & ưu điểm",
        description: valueOf(source, "description"),
        items: (source ? records(source["items"]) : (fallback ?? [])).map((item) => ({
            title: valueOf(item, "title"),
            description: valueOf(item, "description") || valueOf(item, "text"),
        })),
    };
}

function normalizeProcess(
    value: unknown,
    fallback?: ServiceContentStep[],
): ServiceContent["process"] {
    const source = record(value);
    if (!source && !fallback) return undefined;
    return {
        title: valueOf(source, "title") || "Quy trình thực hiện",
        description: valueOf(source, "description") || valueOf(source, "body"),
        steps: (source ? records(source["steps"]) : (fallback ?? [])).map((item) => ({
            title: valueOf(item, "title"),
            description: valueOf(item, "description") || valueOf(item, "text"),
        })),
    };
}

function normalizeResults(value: unknown): ServiceContentResults | undefined {
    const source = record(value);
    if (!source) return undefined;
    return {
        title: valueOf(source, "title") || "Hiệu quả trước & sau",
        description: valueOf(source, "description"),
        disclaimer: valueOf(source, "disclaimer") || DEFAULT_RESULTS_DISCLAIMER,
        cases: records(source["cases"]).map((item) => ({
            before_image: valueOf(item, "before_image"),
            after_image: valueOf(item, "after_image"),
            caption: valueOf(item, "caption"),
        })),
    };
}

function normalizeFaq(
    value: unknown,
    fallback?: ServiceContentFaqItem[],
): ServiceContentFaq | undefined {
    const source = record(value);
    if (!source && !fallback) return undefined;
    return {
        title: valueOf(source, "title") || "Câu hỏi thường gặp",
        items: (source ? records(source["items"]) : (fallback ?? [])).map((item) => ({
            question: valueOf(item, "question") || valueOf(item, "q"),
            answer: valueOf(item, "answer") || valueOf(item, "a"),
        })),
    };
}

/** Normalize the fixed schema and safely convert content from the old block editor. */
export function normalizeServiceContent(value: unknown): ServiceContent | null {
    const source = record(value);
    if (!source) return null;

    const blocks = records(source["blocks"]);
    const overview = blocks.find((item) => item["type"] === "overview");
    const infoGrid = blocks.find((item) => item["type"] === "info_grid");
    const process = blocks.find((item) => item["type"] === "process");
    const faq = blocks.find((item) => item["type"] === "faq");
    const legacyBenefits = [...records(overview?.["cards"]), ...records(infoGrid?.["items"])].map(
        (item) => ({
            title: valueOf(item, "title"),
            description: valueOf(item, "description") || valueOf(item, "text"),
        }),
    );
    const legacyProcess = records(process?.["steps"]).map((item) => ({
        title: valueOf(item, "title"),
        description: valueOf(item, "text") || valueOf(item, "description"),
    }));
    const legacyFaq = records(faq?.["items"]).map((item) => ({
        question: valueOf(item, "question") || valueOf(item, "q"),
        answer: valueOf(item, "answer") || valueOf(item, "a"),
    }));
    const hasFixedContent = ["benefits", "process", "results", "faq"].some((key) => key in source);
    if (!hasFixedContent && blocks.length === 0) return null;

    const normalized: ServiceContent = {};
    const benefits = normalizeBenefits(source["benefits"], legacyBenefits);
    const normalizedProcess = normalizeProcess(source["process"], legacyProcess);
    const results = normalizeResults(source["results"]);
    const normalizedFaq = normalizeFaq(source["faq"], legacyFaq);
    if (benefits) normalized.benefits = benefits;
    if (normalizedProcess) normalized.process = normalizedProcess;
    if (results) normalized.results = results;
    if (normalizedFaq) normalized.faq = normalizedFaq;
    return normalized;
}

function textItem(value: unknown): string {
    return clean(value);
}

function hasSectionText(value: unknown): boolean {
    return Boolean(clean(value));
}

function SectionHeader({ number, title }: { number: string; title: string }) {
    return (
        <div className="mb-8 md:mb-10">
            <p className="mb-3 text-[11px] font-semibold uppercase tracking-[.18em] text-secondary">
                {number}
            </p>
            <div className="flex items-center gap-4 md:gap-7">
                <h2 className="max-w-[82%] shrink-0 text-3xl font-semibold leading-tight text-primary md:max-w-none md:text-4xl lg:text-[2.75rem]">
                    {title}
                </h2>
                <span className="h-px min-w-8 flex-1 bg-primary/35" aria-hidden="true" />
            </div>
        </div>
    );
}

function IntroductionSection({ service }: { service: Service }) {
    const introduction =
        clean(service.introduction) ||
        clean(service.short_description) ||
        clean(service.description) ||
        "Thông tin dịch vụ được cung cấp để bạn tham khảo trước khi trao đổi cùng đội ngũ chuyên môn.";
    const introductionImage = clean(service.image) || clean(service.hero_image) || treatment;

    return (
        <section>
            <SectionHeader number="01" title="Giới thiệu dịch vụ" />
            <div className="grid items-center gap-8 lg:grid-cols-[.85fr_1.15fr] lg:gap-16">
                <div className="max-w-xl">
                    <p className="label-luxury">Tổng quan</p>
                    <h3 className="mt-3 text-2xl font-semibold leading-snug text-primary md:text-3xl">
                        {service.name} là gì?
                    </h3>
                    <p className="mt-5 whitespace-pre-line text-sm leading-7 text-muted-foreground md:text-base md:leading-8">
                        {introduction}
                    </p>
                </div>
                <div className="overflow-hidden rounded-2xl bg-muted shadow-card">
                    <img
                        src={introductionImage}
                        alt={`Hình ảnh minh họa ${service.name}`}
                        className="aspect-[16/10] w-full object-cover"
                        loading="lazy"
                        decoding="async"
                    />
                </div>
            </div>
        </section>
    );
}

function BenefitsSection({ section }: { section: NonNullable<ServiceContent["benefits"]> }) {
    const items = (section.items ?? []).filter(
        (item: ServiceContentBenefit) =>
            hasSectionText(item.title) || hasSectionText(item.description),
    );
    if (!hasSectionText(section.description) && items.length === 0) return null;

    return (
        <section>
            <SectionHeader number="02" title={textItem(section.title) || "Tác dụng & ưu điểm"} />

            {textItem(section.description) && (
                <p className="mb-8 max-w-3xl whitespace-pre-line text-sm leading-7 text-muted-foreground md:text-base md:leading-8">
                    {textItem(section.description)}
                </p>
            )}

            {items.length > 0 && (
                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {items.map((item, index) => {
                        const itemNumber = String(index + 1).padStart(2, "0");
                        return (
                            <article
                                key={`benefit-${index}`}
                                className="relative isolate min-h-56 overflow-hidden rounded-xl border border-primary/20 bg-card p-6 transition duration-300 hover:-translate-y-1 hover:shadow-card md:p-7"
                            >
                                <span
                                    aria-hidden="true"
                                    className="pointer-events-none absolute right-4 top-1 -z-10 font-display text-[5.5rem] leading-none text-primary/[.055] md:text-[6.5rem]"
                                >
                                    {itemNumber}
                                </span>
                                <p className="text-xs font-semibold tracking-[.16em] text-secondary">
                                    {itemNumber}
                                </p>
                                {textItem(item.title) && (
                                    <h3 className="mt-12 text-xl font-semibold leading-snug text-primary md:text-2xl">
                                        {textItem(item.title)}
                                    </h3>
                                )}
                                {textItem(item.description) && (
                                    <p className="mt-3 whitespace-pre-line text-sm leading-7 text-muted-foreground">
                                        {textItem(item.description)}
                                    </p>
                                )}
                            </article>
                        );
                    })}
                </div>
            )}
        </section>
    );
}

function ProcessSection({ section }: { section: NonNullable<ServiceContent["process"]> }) {
    const steps = (section.steps ?? []).filter(
        (item: ServiceContentStep) =>
            hasSectionText(item.title) ||
            hasSectionText(item.description) ||
            hasSectionText(item.text),
    );
    if (steps.length === 0) return null;

    return (
        <section>
            <SectionHeader number="03" title={textItem(section.title) || "Quy trình thực hiện"} />

            {textItem(section.description) && (
                <p className="mb-8 max-w-3xl whitespace-pre-line text-sm leading-7 text-muted-foreground md:text-base md:leading-8">
                    {textItem(section.description)}
                </p>
            )}

            <ol className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                {steps.map((item, index) => {
                    const stepNumber = String(index + 1).padStart(2, "0");
                    return (
                        <li
                            key={`process-${index}`}
                            className="relative isolate min-h-[24rem] overflow-hidden rounded-xl border border-primary/20 bg-card p-6 transition duration-300 hover:-translate-y-1 hover:shadow-card md:p-7"
                        >
                            <span
                                aria-hidden="true"
                                className="pointer-events-none absolute left-5 top-5 -z-10 font-display text-[7.5rem] font-light leading-none text-primary/[.065] sm:text-[8.5rem] lg:text-[9.5rem]"
                            >
                                {stepNumber}.
                            </span>

                            <div className="flex min-h-[20rem] flex-col pt-36 sm:pt-40">
                                {textItem(item.title) && (
                                    <h3 className="text-xl font-semibold leading-[1.08] text-primary md:text-2xl">
                                        {textItem(item.title)}
                                    </h3>
                                )}
                                {(textItem(item.description) || textItem(item.text)) && (
                                    <p className="mt-4 whitespace-pre-line text-sm leading-7 text-muted-foreground">
                                        {textItem(item.description) || textItem(item.text)}
                                    </p>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}

function ResultsSection({ section }: { section: NonNullable<ServiceContent["results"]> }) {
    const cases = (section.cases ?? []).filter(
        (item: ServiceContentResultCase) =>
            hasSectionText(item.before_image) && hasSectionText(item.after_image),
    );
    if (cases.length === 0) return null;

    return (
        <section>
            <SectionHeader number="04" title={textItem(section.title) || "Hiệu quả trước & sau"} />

            {textItem(section.description) && (
                <p className="mb-8 max-w-3xl whitespace-pre-line text-sm leading-7 text-muted-foreground md:text-base md:leading-8">
                    {textItem(section.description)}
                </p>
            )}

            <div className="grid gap-5 lg:grid-cols-2">
                {cases.map((item, index) => (
                    <figure
                        key={`result-${index}`}
                        className="overflow-hidden rounded-xl border border-primary/15 bg-card"
                    >
                        <div className="grid grid-cols-2">
                            <div className="relative overflow-hidden">
                                <img
                                    src={textItem(item.before_image)}
                                    alt={`Trước điều trị - trường hợp ${index + 1}`}
                                    className="aspect-[4/3] w-full object-cover transition duration-500 hover:scale-[1.02]"
                                />
                                <span className="absolute bottom-3 left-3 rounded-full bg-card/90 px-3 py-1 text-[10px] font-semibold uppercase tracking-[.14em] text-primary backdrop-blur-sm">
                                    Trước
                                </span>
                            </div>
                            <div className="relative overflow-hidden border-l">
                                <img
                                    src={textItem(item.after_image)}
                                    alt={`Sau điều trị - trường hợp ${index + 1}`}
                                    className="aspect-[4/3] w-full object-cover transition duration-500 hover:scale-[1.02]"
                                />
                                <span className="absolute bottom-3 left-3 rounded-full bg-card/90 px-3 py-1 text-[10px] font-semibold uppercase tracking-[.14em] text-primary backdrop-blur-sm">
                                    Sau
                                </span>
                            </div>
                        </div>
                        {textItem(item.caption) && (
                            <figcaption className="border-t px-5 py-4 text-sm leading-6 text-muted-foreground">
                                {textItem(item.caption)}
                            </figcaption>
                        )}
                    </figure>
                ))}
            </div>

            <p className="mt-5 text-xs leading-5 text-muted-foreground">
                {textItem(section.disclaimer) || DEFAULT_RESULTS_DISCLAIMER}
            </p>
        </section>
    );
}

function FaqSection({ section }: { section: NonNullable<ServiceContent["faq"]> }) {
    const items = (section.items ?? []).filter(
        (item: ServiceContentFaqItem) =>
            hasSectionText(item.question) || hasSectionText(item.answer),
    );
    if (items.length === 0) return null;

    return (
        <section>
            <SectionHeader number="05" title={textItem(section.title) || "Câu hỏi thường gặp"} />

            <div className="border-y border-primary/15">
                {items.map((item, index) => (
                    <details
                        key={`faq-${index}`}
                        className="group border-b border-primary/15 last:border-b-0"
                    >
                        <summary className="focus-premium flex cursor-pointer list-none items-center justify-between gap-6 py-6 font-semibold text-primary [&::-webkit-details-marker]:hidden md:py-7 md:text-lg">
                            <span className="flex min-w-0 items-start gap-4">
                                <span className="mt-0.5 shrink-0 text-xs font-semibold tracking-[.12em] text-secondary">
                                    {String(index + 1).padStart(2, "0")}
                                </span>
                                <span>{textItem(item.question) || "Câu hỏi"}</span>
                            </span>
                            <ChevronRight
                                size={20}
                                aria-hidden="true"
                                className="shrink-0 transition-transform duration-300 group-open:rotate-90"
                            />
                        </summary>
                        {textItem(item.answer) && (
                            <p className="max-w-3xl pb-7 pl-10 pr-10 whitespace-pre-line text-sm leading-7 text-muted-foreground md:text-base">
                                {textItem(item.answer)}
                            </p>
                        )}
                    </details>
                ))}
            </div>
        </section>
    );
}

export function ServiceDetailView({
    service,
    category,
    preview = false,
    relatedServices = [],
}: ServiceDetailViewProps) {
    const content = normalizeServiceContent(service.content);
    const description =
        clean(service.short_description) ||
        clean(service.description) ||
        "Một lựa chọn để bạn tìm hiểu cùng đội ngũ chuyên môn và cân nhắc theo nhu cầu thực tế.";
    const heroImage = clean(service.hero_image) || clean(service.image) || treatment;
    const disclaimer =
        clean(service.hero_disclaimer) ||
        "Thông tin được cung cấp để bạn tham khảo trước khi trao đổi cùng bác sĩ.";
    const duration = clean(service.duration_note) || `${service.duration} phút tham khảo`;
    const price = money(service.price);
    const priceNote = clean(service.price_note);
    const ctaLabel = clean(service.cta_label) || "Đặt lịch tư vấn";
    const categorySlug = service.category_slug || category?.slug || "";
    const href = `/booking?service_id=${service.id}`;

    return (
        <div className={preview ? "pointer-events-none" : undefined}>
            <section className="bg-card">
                {!preview && (
                    <Container className="py-5">
                        {categorySlug ? (
                            <Link
                                to="/services/$categorySlug"
                                params={{ categorySlug }}
                                className="focus-premium inline-flex items-center gap-2 text-sm text-muted-foreground transition hover:text-primary"
                            >
                                <ChevronLeft size={16} aria-hidden="true" />{" "}
                                {service.category || "Dịch vụ"}
                            </Link>
                        ) : (
                            <a
                                href="/services"
                                className="focus-premium inline-flex items-center gap-2 text-sm text-muted-foreground transition hover:text-primary"
                            >
                                <ChevronLeft size={16} aria-hidden="true" />{" "}
                                {service.category || "Dịch vụ"}
                            </a>
                        )}
                    </Container>
                )}
                <Container className="pb-12 md:pb-20">
                    <div className="grid items-center gap-10 lg:grid-cols-[1.1fr_.9fr] lg:gap-16">
                        <div className="relative overflow-hidden rounded-2xl bg-muted shadow-card">
                            <img
                                src={heroImage}
                                alt={service.name}
                                className="aspect-[4/3] w-full object-cover"
                            />
                            <div className="absolute inset-x-4 bottom-4 flex items-center gap-3 rounded-xl bg-card/95 p-4 shadow-card backdrop-blur-sm">
                                <ShieldCheck
                                    className="shrink-0 text-secondary"
                                    size={22}
                                    aria-hidden="true"
                                />
                                <p className="text-xs leading-5 text-muted-foreground">
                                    {disclaimer}
                                </p>
                            </div>
                        </div>
                        <div>
                            <p className="label-luxury">
                                {service.category || "Liệu trình y khoa"}
                            </p>
                            <h1 className="mt-4 text-4xl font-semibold leading-tight text-primary md:text-6xl">
                                {service.name || "Tên dịch vụ"}
                            </h1>
                            <p className="mt-6 whitespace-pre-line text-base leading-7 text-muted-foreground">
                                {description}
                            </p>
                            <div className="mt-7 flex max-w-xl flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                                <span className="inline-flex items-center gap-2">
                                    <Clock3 size={17} aria-hidden="true" /> {duration}
                                </span>
                                <strong className="ml-auto rounded-full border border-secondary/70 bg-secondary px-4 py-2 text-primary shadow-sm">
                                    {price}
                                </strong>
                            </div>
                            {priceNote && (
                                <p className="mt-3 text-xs leading-5 text-muted-foreground">
                                    {priceNote}
                                </p>
                            )}
                            <ButtonLink to={href} className="mt-8">
                                <CalendarDays size={16} aria-hidden="true" /> {ctaLabel}
                            </ButtonLink>
                        </div>
                    </div>
                </Container>
            </section>
            <Container className="section-space">
                <div className="grid gap-24 md:gap-28">
                    <IntroductionSection service={service} />
                    {content?.benefits && <BenefitsSection section={content.benefits} />}
                    {content?.process && <ProcessSection section={content.process} />}
                    {content?.results && <ResultsSection section={content.results} />}
                    {content?.faq && <FaqSection section={content.faq} />}
                </div>
                {!preview && relatedServices.length > 0 && (
                    <section className="mt-24 md:mt-28">
                        <div className="flex flex-wrap items-end justify-between gap-5">
                            <div className="min-w-0 flex-1">
                                <p className="mb-3 text-[11px] font-semibold uppercase tracking-[.18em] text-secondary">
                                    Cùng danh mục
                                </p>
                                <div className="flex items-center gap-4 md:gap-7">
                                    <h2 className="shrink-0 text-3xl font-semibold text-primary md:text-4xl lg:text-[2.75rem]">
                                        Có thể bạn cũng quan tâm
                                    </h2>
                                    <span
                                        className="hidden h-px min-w-8 flex-1 bg-primary/35 sm:block"
                                        aria-hidden="true"
                                    />
                                </div>
                            </div>
                            {categorySlug && (
                                <Link
                                    to="/services/$categorySlug"
                                    params={{ categorySlug }}
                                    className="focus-premium inline-flex items-center gap-2 text-sm font-semibold text-primary"
                                >
                                    Xem danh mục <ChevronRight size={16} aria-hidden="true" />
                                </Link>
                            )}
                        </div>
                        <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {relatedServices.slice(0, 3).map((related) => (
                                <ServiceExplorerCard
                                    key={related.id}
                                    service={related}
                                    category={category ?? undefined}
                                />
                            ))}
                        </div>
                    </section>
                )}
                <div className="mt-20">
                    <BookingCTA serviceId={service.id} title="Cùng tìm lựa chọn phù hợp cho bạn" />
                </div>
            </Container>
        </div>
    );
}
