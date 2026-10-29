import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from "react";
import { Plus, RotateCcw, Trash2, Upload } from "lucide-react";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Button } from "@/components/common/Button";
import { OperationNotice } from "@/components/common/Feedback";
import { Field, Input, Textarea } from "@/components/common/Fields";
import {
    ServiceDetailView,
    normalizeServiceContent,
} from "@/components/services/ServiceDetailView";
import type { Service, ServiceCategory, ServiceContent } from "@/types";

export type ServiceFormProps = {
    service?: Service | null;
    categories: ServiceCategory[];
    categoriesLoading?: boolean;
    categoriesError?: boolean;
    isSubmitting?: boolean;
    serverErrors?: Record<string, string>;
    notice?: string;
    onDismissNotice?: () => void;
    onSubmit: (payload: FormData) => Promise<void>;
    submitLabel?: string;
};

type FormState = {
    name: string;
    slug: string;
    categoryId: string;
    duration: string;
    durationNote: string;
    price: string;
    priceNote: string;
    shortDescription: string;
    introduction: string;
    heroDisclaimer: string;
    ctaLabel: string;
    status: "active" | "inactive";
    sortOrder: string;
    seoTitle: string;
    seoDescription: string;
    content: ServiceContent;
};

type ResultUpload = { before?: File; after?: File };
type ExistingResultImages = {
    before_image?: string | null;
    after_image?: string | null;
};

const selectClass =
    "min-h-12 w-full rounded-md border border-input bg-card px-4 text-sm outline-none transition focus:border-primary focus:ring-1 focus:ring-primary";
const DEFAULT_RESULTS_DISCLAIMER = "Kết quả và trải nghiệm có thể khác nhau tùy từng trường hợp.";

function clean(value: unknown): string {
    return typeof value === "string" || typeof value === "number" ? String(value) : "";
}

function slugify(value: string): string {
    return value
        .trim()
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/đ/g, "d")
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-+|-+$/g, "");
}

export function createDefaultServiceContent(): ServiceContent {
    return {
        benefits: { title: "Tác dụng & ưu điểm", description: "", items: [] },
        process: { title: "Quy trình thực hiện", description: "", steps: [] },
        results: {
            title: "Hiệu quả trước & sau",
            description: "",
            cases: [],
            disclaimer: DEFAULT_RESULTS_DISCLAIMER,
        },
        faq: { title: "Câu hỏi thường gặp", items: [] },
    };
}

function ensureContent(value: ServiceContent): ServiceContent {
    const defaults = createDefaultServiceContent();
    return {
        benefits: { ...defaults.benefits, ...value.benefits, items: value.benefits?.items ?? [] },
        process: { ...defaults.process, ...value.process, steps: value.process?.steps ?? [] },
        results: { ...defaults.results, ...value.results, cases: value.results?.cases ?? [] },
        faq: { ...defaults.faq, ...value.faq, items: value.faq?.items ?? [] },
    };
}

function removeIndexedRecord<T>(items: Record<string, T>, index: number): Record<string, T> {
    return Object.fromEntries(
        Object.entries(items)
            .filter(([key]) => Number(key) !== index)
            .map(([key, value]) => {
                const numericKey = Number(key);
                return [String(numericKey > index ? numericKey - 1 : numericKey), value];
            }),
    );
}

function initialState(service?: Service | null): FormState {
    const normalized = normalizeServiceContent(service?.content);
    return {
        name: service?.name ?? "",
        slug: service?.slug ?? "",
        categoryId: service?.category_id ? String(service.category_id) : "",
        duration: service?.duration ? String(service.duration) : "",
        durationNote: service?.duration_note ?? "",
        price: service?.price ?? "",
        priceNote: service?.price_note ?? "",
        shortDescription: service?.short_description ?? service?.description ?? "",
        introduction: service?.introduction ?? "",
        heroDisclaimer: service?.hero_disclaimer ?? "",
        ctaLabel: service?.cta_label ?? "",
        status: service?.status ?? "active",
        sortOrder: service?.sort_order !== undefined ? String(service.sort_order) : "0",
        seoTitle: service?.seo_title ?? "",
        seoDescription: service?.seo_description ?? "",
        content: normalized ? ensureContent(normalized) : createDefaultServiceContent(),
    };
}

function displayError(errors: Record<string, string> | undefined, key: string): string | undefined {
    if (!errors) return undefined;
    return (
        errors[key] || Object.entries(errors).find(([field]) => field.startsWith(`${key}.`))?.[1]
    );
}

function buildServicePayload(
    state: FormState,
    service: Service | null | undefined,
    image?: File,
    heroImage?: File,
    resultImages: Record<string, ResultUpload> = {},
): FormData {
    const payload = new FormData();
    const append = (key: string, value: string | number | null | undefined) => {
        if (value !== null && value !== undefined) payload.append(key, String(value));
    };
    append("name", state.name.trim());
    append("slug", state.slug.trim());
    append("category_id", state.categoryId);
    append("duration", state.duration);
    append("duration_note", state.durationNote.trim());
    append("price", state.price);
    append("price_note", state.priceNote.trim());
    append("short_description", state.shortDescription.trim());
    append("description", state.shortDescription.trim());
    append("introduction", state.introduction.trim());
    append("hero_disclaimer", state.heroDisclaimer.trim());
    append("cta_label", state.ctaLabel.trim());
    append("status", state.status);
    append("sort_order", state.sortOrder);
    append("seo_title", state.seoTitle.trim());
    append("seo_description", state.seoDescription.trim());
    payload.append("content", JSON.stringify(state.content));
    if (image) payload.append("image", image);
    if (heroImage) payload.append("hero_image", heroImage);
    Object.entries(resultImages).forEach(([index, files]) => {
        if (files.before) payload.append(`result_images[${index}][before_image]`, files.before);
        if (files.after) payload.append(`result_images[${index}][after_image]`, files.after);
    });
    if (service) payload.append("_method", "PATCH");
    return payload;
}

function SectionAccordion({
    number,
    title,
    children,
}: {
    number: string;
    title: string;
    children: ReactNode;
}) {
    return (
        <details open className="card-surface overflow-hidden">
            <summary className="focus-premium flex cursor-pointer list-none items-center gap-4 px-5 py-4 text-primary [&::-webkit-details-marker]:hidden">
                <span className="font-display text-2xl text-secondary">{number}</span>
                <span className="font-semibold">{title}</span>
                <span aria-hidden="true" className="ml-auto text-lg text-muted-foreground">
                    ⌄
                </span>
            </summary>
            <div className="grid gap-5 border-t p-5">{children}</div>
        </details>
    );
}

function RemoveButton({ label, onClick }: { label: string; onClick: () => void }) {
    return (
        <button
            type="button"
            aria-label={label}
            className="focus-premium rounded-md p-2 text-red-700 transition hover:bg-red-50"
            onClick={onClick}
        >
            <Trash2 size={16} aria-hidden="true" />
        </button>
    );
}

function UploadField({
    label,
    existing,
    error,
    onChange,
}: {
    label: string;
    existing: string | null;
    error?: string | undefined;
    onChange: (file?: File) => void;
}) {
    return (
        <Field label={label} error={error}>
            <div className="grid gap-3">
                <Input
                    type="file"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    onChange={(event) => onChange(event.target.files?.[0])}
                    aria-invalid={Boolean(error) || undefined}
                />
                {existing && (
                    <img
                        src={existing}
                        alt={`${label} hiện tại`}
                        className="h-28 w-full rounded-lg border object-cover"
                    />
                )}
            </div>
        </Field>
    );
}

export function ServiceForm({
    service,
    categories,
    categoriesLoading = false,
    categoriesError = false,
    isSubmitting = false,
    serverErrors = {},
    notice = "",
    onDismissNotice = () => undefined,
    onSubmit,
    submitLabel,
}: ServiceFormProps) {
    const [state, setState] = useState<FormState>(() => initialState(service));
    const [slugTouched, setSlugTouched] = useState(Boolean(service?.slug));
    const [activeTab, setActiveTab] = useState("basic");
    const [image, setImage] = useState<File>();
    const [heroImage, setHeroImage] = useState<File>();
    const [imagePreview, setImagePreview] = useState<string | null>(null);
    const [heroImagePreview, setHeroImagePreview] = useState<string | null>(null);
    const [resultImages, setResultImages] = useState<Record<string, ResultUpload>>({});
    const [resultPreviews, setResultPreviews] = useState<
        Record<string, { before?: string; after?: string }>
    >({});
    const [existingResultImages, setExistingResultImages] = useState<
        Record<string, ExistingResultImages>
    >(() => ({ ...(service?.result_image_urls ?? {}) }));
    const previewUrls = useRef<string[]>([]);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>({});

    useEffect(() => () => previewUrls.current.forEach((url) => URL.revokeObjectURL(url)), []);

    function updateField<K extends keyof FormState>(key: K, value: FormState[K]) {
        setState((current) => ({ ...current, [key]: value }));
    }

    function updateContent(transform: (content: ServiceContent) => ServiceContent) {
        setState((current) => ({
            ...current,
            content: transform(current.content),
        }));
    }

    function setMainUpload(kind: "image" | "hero", file?: File) {
        const preview = file ? URL.createObjectURL(file) : null;
        if (preview) previewUrls.current.push(preview);
        if (kind === "image") {
            setImage(file);
            setImagePreview(preview);
        } else {
            setHeroImage(file);
            setHeroImagePreview(preview);
        }
    }

    function setResultUpload(index: number, kind: "before" | "after", file?: File) {
        const key = String(index);
        const preview = file ? URL.createObjectURL(file) : undefined;
        if (preview) previewUrls.current.push(preview);
        setResultImages((current) => ({
            ...current,
            [key]: { ...current[key], [kind]: file },
        }));
        setResultPreviews((current) => ({
            ...current,
            [key]: { ...current[key], [kind]: preview },
        }));
    }

    function validate(): boolean {
        const errors: Record<string, string> = {};
        if (!state.name.trim()) errors["name"] = "Vui lòng nhập tên dịch vụ.";
        if (!state.slug.trim()) errors["slug"] = "Vui lòng nhập slug.";
        if (!state.categoryId) errors["category_id"] = "Vui lòng chọn danh mục.";
        if (!state.duration || Number(state.duration) < 1)
            errors["duration"] = "Thời lượng phải lớn hơn 0.";
        if (state.price === "" || Number(state.price) < 0)
            errors["price"] = "Vui lòng nhập giá hợp lệ.";
        setClientErrors(errors);
        return Object.keys(errors).length === 0;
    }

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (validate())
            await onSubmit(buildServicePayload(state, service, image, heroImage, resultImages));
    }

    function setImagePreviewUpload(kind: "image" | "hero", file?: File) {
        setMainUpload(kind, file);
    }

    const previewContent = useMemo<ServiceContent>(() => {
        const content = ensureContent(state.content);
        return {
            ...content,
            results: {
                ...content.results,
                cases: (content.results?.cases ?? []).map((item, index) => ({
                    ...item,
                    before_image:
                        resultPreviews[String(index)]?.before ||
                        existingResultImages[String(index)]?.before_image ||
                        item.before_image ||
                        null,
                    after_image:
                        resultPreviews[String(index)]?.after ||
                        existingResultImages[String(index)]?.after_image ||
                        item.after_image ||
                        null,
                })),
            },
        };
    }, [existingResultImages, resultPreviews, state.content]);

    const previewService = useMemo<Service>(
        () => ({
            id: service?.id ?? 0,
            slug: state.slug,
            name: state.name,
            category:
                categories.find((category) => String(category.id) === state.categoryId)?.name ??
                service?.category ??
                null,
            category_slug: service?.category_slug ?? null,
            category_id: state.categoryId ? Number(state.categoryId) : null,
            description: state.shortDescription,
            introduction: state.introduction,
            short_description: state.shortDescription,
            duration: Number(state.duration) || 0,
            duration_note: state.durationNote,
            price: state.price,
            price_note: state.priceNote,
            image: imagePreview || service?.image || null,
            hero_image: heroImagePreview || service?.hero_image || null,
            hero_disclaimer: state.heroDisclaimer,
            cta_label: state.ctaLabel,
            sort_order: Number(state.sortOrder) || 0,
            status: state.status,
            seo_title: state.seoTitle,
            seo_description: state.seoDescription,
            content: previewContent,
        }),
        [categories, heroImagePreview, imagePreview, previewContent, service, state],
    );

    const errors = { ...serverErrors, ...clientErrors };
    const existingImage = imagePreview || service?.image || null;
    const existingHeroImage = heroImagePreview || service?.hero_image || null;
    const content = state.content;
    const benefits = content.benefits;
    const process = content.process;
    const results = content.results;
    const faq = content.faq;

    return (
        <form onSubmit={submit} className="grid gap-6">
            <Tabs value={activeTab} onValueChange={setActiveTab} className="grid gap-5">
                <TabsList className="grid h-auto w-full grid-cols-2 gap-1 sm:grid-cols-4">
                    <TabsTrigger value="basic">Thông tin cơ bản</TabsTrigger>
                    <TabsTrigger value="detail">Thông tin chi tiết</TabsTrigger>
                    <TabsTrigger value="preview">Xem trước</TabsTrigger>
                    <TabsTrigger value="seo">SEO</TabsTrigger>
                </TabsList>
                <TabsContent
                    value="basic"
                    className="card-surface mt-0 grid gap-4 p-5 md:grid-cols-2"
                >
                    <Field label="Tên dịch vụ *" error={displayError(errors, "name")}>
                        <Input
                            value={state.name}
                            required
                            onChange={(event) => {
                                const name = event.target.value;
                                updateField("name", name);
                                if (!slugTouched) updateField("slug", slugify(name));
                            }}
                            aria-invalid={Boolean(errors["name"]) || undefined}
                        />
                    </Field>
                    <Field label="Slug / Đường dẫn *" error={displayError(errors, "slug")}>
                        <div className="flex gap-2">
                            <Input
                                value={state.slug}
                                required
                                onChange={(event) => {
                                    setSlugTouched(true);
                                    updateField("slug", slugify(event.target.value));
                                }}
                                aria-invalid={Boolean(errors["slug"]) || undefined}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                className="shrink-0 px-3"
                                aria-label="Tạo lại slug"
                                onClick={() => updateField("slug", slugify(state.name))}
                            >
                                <RotateCcw size={16} aria-hidden="true" />
                            </Button>
                        </div>
                    </Field>
                    <Field label="Danh mục *" error={displayError(errors, "category_id")}>
                        <select
                            className={selectClass}
                            value={state.categoryId}
                            required
                            disabled={categoriesLoading || categoriesError}
                            onChange={(event) => updateField("categoryId", event.target.value)}
                        >
                            <option value="">
                                {categoriesLoading ? "Đang tải danh mục..." : "Chọn danh mục"}
                            </option>
                            {categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </select>
                        {categoriesError && (
                            <span role="alert" className="text-xs text-red-700">
                                Không thể tải danh mục.
                            </span>
                        )}
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Thời lượng (phút) *" error={displayError(errors, "duration")}>
                            <Input
                                type="number"
                                min={1}
                                value={state.duration}
                                required
                                onChange={(event) => updateField("duration", event.target.value)}
                            />
                        </Field>
                        <Field
                            label="Ghi chú thời lượng"
                            error={displayError(errors, "duration_note")}
                        >
                            <Input
                                value={state.durationNote}
                                onChange={(event) =>
                                    updateField("durationNote", event.target.value)
                                }
                                placeholder="30–45 phút"
                            />
                        </Field>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Giá *" error={displayError(errors, "price")}>
                            <Input
                                type="number"
                                min={0}
                                step="0.01"
                                value={state.price}
                                required
                                onChange={(event) => updateField("price", event.target.value)}
                            />
                        </Field>
                        <Field label="Ghi chú giá" error={displayError(errors, "price_note")}>
                            <Input
                                value={state.priceNote}
                                onChange={(event) => updateField("priceNote", event.target.value)}
                                placeholder="Từ 500.000 VNĐ"
                            />
                        </Field>
                    </div>
                    <Field label="Trạng thái" error={displayError(errors, "status")}>
                        <select
                            className={selectClass}
                            value={state.status}
                            onChange={(event) =>
                                updateField("status", event.target.value as FormState["status"])
                            }
                        >
                            <option value="active">Hoạt động</option>
                            <option value="inactive">Tạm ngưng</option>
                        </select>
                    </Field>
                    <Field label="Thứ tự hiển thị" error={displayError(errors, "sort_order")}>
                        <Input
                            type="number"
                            min={0}
                            value={state.sortOrder}
                            onChange={(event) => updateField("sortOrder", event.target.value)}
                        />
                    </Field>
                    <Field label="Mô tả ngắn" error={displayError(errors, "short_description")}>
                        <Textarea
                            value={state.shortDescription}
                            onChange={(event) =>
                                updateField("shortDescription", event.target.value)
                            }
                            placeholder="Nội dung hiển thị ở phần giới thiệu..."
                        />
                    </Field>
                    <Field label="Giới thiệu dịch vụ" error={displayError(errors, "introduction")}>
                        <Textarea
                            className="min-h-36"
                            value={state.introduction}
                            onChange={(event) => updateField("introduction", event.target.value)}
                            placeholder="Dịch vụ này là gì?"
                        />
                    </Field>
                    <Field
                        label="Dòng lưu ý dưới ảnh"
                        error={displayError(errors, "hero_disclaimer")}
                    >
                        <Textarea
                            value={state.heroDisclaimer}
                            onChange={(event) => updateField("heroDisclaimer", event.target.value)}
                        />
                    </Field>
                    <Field label="Nhãn CTA" error={displayError(errors, "cta_label")}>
                        <Input
                            value={state.ctaLabel}
                            onChange={(event) => updateField("ctaLabel", event.target.value)}
                            placeholder="Đặt lịch tư vấn"
                        />
                    </Field>
                    <UploadField
                        label="Ảnh dịch vụ / thumbnail"
                        existing={existingImage}
                        onChange={(file) => setImagePreviewUpload("image", file)}
                        error={displayError(errors, "image")}
                    />
                    <UploadField
                        label="Ảnh Hero"
                        existing={existingHeroImage}
                        onChange={(file) => setImagePreviewUpload("hero", file)}
                        error={displayError(errors, "hero_image")}
                    />
                </TabsContent>
                <TabsContent value="detail" className="mt-0 grid gap-5">
                    <p className="text-sm text-muted-foreground">
                        Bốn phần dưới đây có thứ tự cố định. Bạn chỉ cần mở từng phần để nhập nội
                        dung.
                    </p>
                    <SectionAccordion number="02" title="Tác dụng & ưu điểm">
                        <Field label="Tiêu đề">
                            <Input
                                value={benefits?.title ?? ""}
                                onChange={(event) =>
                                    updateContent((current) => ({
                                        ...current,
                                        benefits: {
                                            ...current.benefits,
                                            title: event.target.value,
                                        },
                                    }))
                                }
                            />
                        </Field>
                        <Field label="Mô tả mở đầu">
                            <Textarea
                                value={benefits?.description ?? ""}
                                onChange={(event) =>
                                    updateContent((current) => ({
                                        ...current,
                                        benefits: {
                                            ...current.benefits,
                                            description: event.target.value,
                                        },
                                    }))
                                }
                            />
                        </Field>
                        <div className="grid gap-3">
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-sm font-semibold">Danh sách ưu điểm</span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-9 px-3 text-xs"
                                    onClick={() =>
                                        updateContent((current) => ({
                                            ...current,
                                            benefits: {
                                                ...current.benefits,
                                                items: [
                                                    ...(current.benefits?.items ?? []),
                                                    { title: "", description: "" },
                                                ],
                                            },
                                        }))
                                    }
                                >
                                    <Plus size={14} aria-hidden="true" /> Thêm ưu điểm
                                </Button>
                            </div>
                            {(benefits?.items ?? []).map((item, index) => (
                                <div
                                    key={`benefit-${index}`}
                                    className="grid gap-3 rounded-lg border bg-muted/20 p-4 sm:grid-cols-[1fr_1fr_auto]"
                                >
                                    <Input
                                        aria-label={`Tên ưu điểm ${index + 1}`}
                                        placeholder="Tên ưu điểm"
                                        value={item.title ?? ""}
                                        onChange={(event) =>
                                            updateContent((current) => ({
                                                ...current,
                                                benefits: {
                                                    ...current.benefits,
                                                    items: (current.benefits?.items ?? []).map(
                                                        (entry, itemIndex) =>
                                                            itemIndex === index
                                                                ? {
                                                                      ...entry,
                                                                      title: event.target.value,
                                                                  }
                                                                : entry,
                                                    ),
                                                },
                                            }))
                                        }
                                    />
                                    <Textarea
                                        aria-label={`Mô tả ưu điểm ${index + 1}`}
                                        className="min-h-20"
                                        placeholder="Mô tả"
                                        value={item.description ?? ""}
                                        onChange={(event) =>
                                            updateContent((current) => ({
                                                ...current,
                                                benefits: {
                                                    ...current.benefits,
                                                    items: (current.benefits?.items ?? []).map(
                                                        (entry, itemIndex) =>
                                                            itemIndex === index
                                                                ? {
                                                                      ...entry,
                                                                      description:
                                                                          event.target.value,
                                                                  }
                                                                : entry,
                                                    ),
                                                },
                                            }))
                                        }
                                    />
                                    <RemoveButton
                                        label={`Xóa ưu điểm ${index + 1}`}
                                        onClick={() =>
                                            updateContent((current) => ({
                                                ...current,
                                                benefits: {
                                                    ...current.benefits,
                                                    items: (current.benefits?.items ?? []).filter(
                                                        (_, itemIndex) => itemIndex !== index,
                                                    ),
                                                },
                                            }))
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                    </SectionAccordion>
                    <SectionAccordion number="03" title="Quy trình thực hiện">
                        <Field label="Tiêu đề">
                            <Input
                                value={process?.title ?? ""}
                                onChange={(event) =>
                                    updateContent((current) => ({
                                        ...current,
                                        process: {
                                            ...current.process,
                                            title: event.target.value,
                                        },
                                    }))
                                }
                            />
                        </Field>
                        <Field label="Mô tả">
                            <Textarea
                                value={process?.description ?? ""}
                                onChange={(event) =>
                                    updateContent((current) => ({
                                        ...current,
                                        process: {
                                            ...current.process,
                                            description: event.target.value,
                                        },
                                    }))
                                }
                            />
                        </Field>
                        <div className="grid gap-3">
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-sm font-semibold">Các bước</span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-9 px-3 text-xs"
                                    onClick={() =>
                                        updateContent((current) => ({
                                            ...current,
                                            process: {
                                                ...current.process,
                                                steps: [
                                                    ...(current.process?.steps ?? []),
                                                    { title: "", description: "" },
                                                ],
                                            },
                                        }))
                                    }
                                >
                                    <Plus size={14} aria-hidden="true" /> Thêm bước
                                </Button>
                            </div>
                            {(process?.steps ?? []).map((item, index) => (
                                <div
                                    key={`step-${index}`}
                                    className="grid gap-3 rounded-lg border bg-muted/20 p-4 sm:grid-cols-[auto_1fr_auto]"
                                >
                                    <span className="pt-2 font-display text-2xl text-secondary">
                                        {String(index + 1).padStart(2, "0")}
                                    </span>
                                    <div className="grid gap-3">
                                        <Input
                                            aria-label={`Tên bước ${index + 1}`}
                                            placeholder="Tên bước"
                                            value={item.title ?? ""}
                                            onChange={(event) =>
                                                updateContent((current) => ({
                                                    ...current,
                                                    process: {
                                                        ...current.process,
                                                        steps: (current.process?.steps ?? []).map(
                                                            (entry, itemIndex) =>
                                                                itemIndex === index
                                                                    ? {
                                                                          ...entry,
                                                                          title: event.target.value,
                                                                      }
                                                                    : entry,
                                                        ),
                                                    },
                                                }))
                                            }
                                        />
                                        <Textarea
                                            aria-label={`Mô tả bước ${index + 1}`}
                                            className="min-h-20"
                                            placeholder="Mô tả bước"
                                            value={item.description ?? item.text ?? ""}
                                            onChange={(event) =>
                                                updateContent((current) => ({
                                                    ...current,
                                                    process: {
                                                        ...current.process,
                                                        steps: (current.process?.steps ?? []).map(
                                                            (entry, itemIndex) =>
                                                                itemIndex === index
                                                                    ? {
                                                                          ...entry,
                                                                          description:
                                                                              event.target.value,
                                                                      }
                                                                    : entry,
                                                        ),
                                                    },
                                                }))
                                            }
                                        />
                                    </div>
                                    <RemoveButton
                                        label={`Xóa bước ${index + 1}`}
                                        onClick={() =>
                                            updateContent((current) => ({
                                                ...current,
                                                process: {
                                                    ...current.process,
                                                    steps: (current.process?.steps ?? []).filter(
                                                        (_, itemIndex) => itemIndex !== index,
                                                    ),
                                                },
                                            }))
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                    </SectionAccordion>
                    <SectionAccordion number="04" title="Hiệu quả trước & sau">
                        <Field label="Tiêu đề">
                            <Input
                                value={results?.title ?? ""}
                                onChange={(event) =>
                                    updateContent((current) => ({
                                        ...current,
                                        results: {
                                            ...current.results,
                                            title: event.target.value,
                                        },
                                    }))
                                }
                            />
                        </Field>
                        <Field label="Mô tả">
                            <Textarea
                                value={results?.description ?? ""}
                                onChange={(event) =>
                                    updateContent((current) => ({
                                        ...current,
                                        results: {
                                            ...current.results,
                                            description: event.target.value,
                                        },
                                    }))
                                }
                            />
                        </Field>
                        <div className="grid gap-4">
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-sm font-semibold">Các trường hợp</span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-9 px-3 text-xs"
                                    onClick={() =>
                                        updateContent((current) => ({
                                            ...current,
                                            results: {
                                                ...current.results,
                                                cases: [
                                                    ...(current.results?.cases ?? []),
                                                    {
                                                        before_image: "",
                                                        after_image: "",
                                                        caption: "",
                                                    },
                                                ],
                                            },
                                        }))
                                    }
                                >
                                    <Plus size={14} aria-hidden="true" /> Thêm trường hợp
                                </Button>
                            </div>
                            {(results?.cases ?? []).map((item, index) => {
                                const preview = resultPreviews[String(index)] ?? {};
                                const existingBefore =
                                    existingResultImages[String(index)]?.before_image ||
                                    item.before_image ||
                                    null;
                                const existingAfter =
                                    existingResultImages[String(index)]?.after_image ||
                                    item.after_image ||
                                    null;
                                return (
                                    <div
                                        key={`case-${index}`}
                                        className="grid gap-4 rounded-lg border bg-muted/20 p-4"
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <span className="text-sm font-semibold">
                                                Case {String(index + 1).padStart(2, "0")}
                                            </span>
                                            <RemoveButton
                                                label={`Xóa trường hợp ${index + 1}`}
                                                onClick={() => {
                                                    setResultImages((current) => {
                                                        return removeIndexedRecord(current, index);
                                                    });
                                                    setResultPreviews((current) => {
                                                        return removeIndexedRecord(current, index);
                                                    });
                                                    setExistingResultImages((current) =>
                                                        removeIndexedRecord(current, index),
                                                    );
                                                    updateContent((current) => ({
                                                        ...current,
                                                        results: {
                                                            ...current.results,
                                                            cases: (
                                                                current.results?.cases ?? []
                                                            ).filter(
                                                                (_, caseIndex) =>
                                                                    caseIndex !== index,
                                                            ),
                                                        },
                                                    }));
                                                }}
                                            />
                                        </div>
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <Field label="Ảnh trước">
                                                <Input
                                                    type="file"
                                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                                    onChange={(event) =>
                                                        setResultUpload(
                                                            index,
                                                            "before",
                                                            event.target.files?.[0],
                                                        )
                                                    }
                                                />
                                                {(preview.before || existingBefore) && (
                                                    <img
                                                        src={preview.before || existingBefore || ""}
                                                        alt={`Ảnh trước case ${index + 1}`}
                                                        className="mt-3 aspect-[4/3] w-full rounded-lg border object-cover"
                                                    />
                                                )}
                                            </Field>
                                            <Field label="Ảnh sau">
                                                <Input
                                                    type="file"
                                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                                    onChange={(event) =>
                                                        setResultUpload(
                                                            index,
                                                            "after",
                                                            event.target.files?.[0],
                                                        )
                                                    }
                                                />
                                                {(preview.after || existingAfter) && (
                                                    <img
                                                        src={preview.after || existingAfter || ""}
                                                        alt={`Ảnh sau case ${index + 1}`}
                                                        className="mt-3 aspect-[4/3] w-full rounded-lg border object-cover"
                                                    />
                                                )}
                                            </Field>
                                        </div>
                                        <Field label="Chú thích">
                                            <Textarea
                                                value={item.caption ?? ""}
                                                onChange={(event) =>
                                                    updateContent((current) => ({
                                                        ...current,
                                                        results: {
                                                            ...current.results,
                                                            cases: (
                                                                current.results?.cases ?? []
                                                            ).map((entry, caseIndex) =>
                                                                caseIndex === index
                                                                    ? {
                                                                          ...entry,
                                                                          caption:
                                                                              event.target.value,
                                                                      }
                                                                    : entry,
                                                            ),
                                                        },
                                                    }))
                                                }
                                            />
                                        </Field>
                                    </div>
                                );
                            })}
                        </div>
                        <Field label="Dòng lưu ý">
                            <Textarea
                                value={results?.disclaimer ?? ""}
                                onChange={(event) =>
                                    updateContent((current) => ({
                                        ...current,
                                        results: {
                                            ...current.results,
                                            disclaimer: event.target.value,
                                        },
                                    }))
                                }
                                placeholder={DEFAULT_RESULTS_DISCLAIMER}
                            />
                        </Field>
                    </SectionAccordion>
                    <SectionAccordion number="05" title="Câu hỏi thường gặp">
                        <Field label="Tiêu đề">
                            <Input
                                value={faq?.title ?? ""}
                                onChange={(event) =>
                                    updateContent((current) => ({
                                        ...current,
                                        faq: { ...current.faq, title: event.target.value },
                                    }))
                                }
                            />
                        </Field>
                        <div className="grid gap-3">
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-sm font-semibold">Câu hỏi</span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-9 px-3 text-xs"
                                    onClick={() =>
                                        updateContent((current) => ({
                                            ...current,
                                            faq: {
                                                ...current.faq,
                                                items: [
                                                    ...(current.faq?.items ?? []),
                                                    { question: "", answer: "" },
                                                ],
                                            },
                                        }))
                                    }
                                >
                                    <Plus size={14} aria-hidden="true" /> Thêm câu hỏi
                                </Button>
                            </div>
                            {(faq?.items ?? []).map((item, index) => (
                                <div
                                    key={`faq-${index}`}
                                    className="grid gap-3 rounded-lg border bg-muted/20 p-4"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-xs font-semibold text-muted-foreground">
                                            Câu hỏi {index + 1}
                                        </span>
                                        <RemoveButton
                                            label={`Xóa câu hỏi ${index + 1}`}
                                            onClick={() =>
                                                updateContent((current) => ({
                                                    ...current,
                                                    faq: {
                                                        ...current.faq,
                                                        items: (current.faq?.items ?? []).filter(
                                                            (_, itemIndex) => itemIndex !== index,
                                                        ),
                                                    },
                                                }))
                                            }
                                        />
                                    </div>
                                    <Input
                                        placeholder="Câu hỏi"
                                        value={item.question ?? ""}
                                        onChange={(event) =>
                                            updateContent((current) => ({
                                                ...current,
                                                faq: {
                                                    ...current.faq,
                                                    items: (current.faq?.items ?? []).map(
                                                        (entry, itemIndex) =>
                                                            itemIndex === index
                                                                ? {
                                                                      ...entry,
                                                                      question: event.target.value,
                                                                  }
                                                                : entry,
                                                    ),
                                                },
                                            }))
                                        }
                                    />
                                    <Textarea
                                        className="min-h-20"
                                        placeholder="Câu trả lời"
                                        value={item.answer ?? ""}
                                        onChange={(event) =>
                                            updateContent((current) => ({
                                                ...current,
                                                faq: {
                                                    ...current.faq,
                                                    items: (current.faq?.items ?? []).map(
                                                        (entry, itemIndex) =>
                                                            itemIndex === index
                                                                ? {
                                                                      ...entry,
                                                                      answer: event.target.value,
                                                                  }
                                                                : entry,
                                                    ),
                                                },
                                            }))
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                    </SectionAccordion>
                </TabsContent>
                <TabsContent value="preview" className="mt-0">
                    <div className="overflow-hidden rounded-2xl border bg-background">
                        <ServiceDetailView service={previewService} preview />
                    </div>
                </TabsContent>
                <TabsContent
                    value="seo"
                    className="card-surface mt-0 grid gap-5 p-5 md:grid-cols-2"
                >
                    <Field label="SEO title" error={displayError(errors, "seo_title")}>
                        <Input
                            maxLength={255}
                            value={state.seoTitle}
                            onChange={(event) => updateField("seoTitle", event.target.value)}
                        />
                    </Field>
                    <Field label="SEO description" error={displayError(errors, "seo_description")}>
                        <Textarea
                            maxLength={10000}
                            value={state.seoDescription}
                            onChange={(event) => updateField("seoDescription", event.target.value)}
                        />
                    </Field>
                    <div className="rounded-xl border bg-muted/20 p-5 md:col-span-2">
                        <p className="text-xs font-semibold uppercase tracking-[.12em] text-secondary">
                            Snippet xem trước
                        </p>
                        <p className="mt-3 text-lg font-semibold text-primary">
                            {state.seoTitle || state.name || "Tên dịch vụ"}
                        </p>
                        <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                            {state.seoDescription ||
                                state.shortDescription ||
                                "Mô tả dịch vụ sẽ hiển thị tại đây."}
                        </p>
                    </div>
                </TabsContent>
            </Tabs>
            <OperationNotice
                message={notice}
                success={notice.startsWith("Đã")}
                onClose={onDismissNotice}
            />
            {Object.keys(errors).length > 0 && !notice && (
                <p role="alert" className="rounded-md bg-red-50 p-3 text-sm text-red-700">
                    Vui lòng kiểm tra các trường được đánh dấu.
                </p>
            )}
            <Button
                type="submit"
                disabled={
                    isSubmitting || categoriesLoading || categoriesError || categories.length === 0
                }
            >
                {isSubmitting
                    ? "Đang lưu..."
                    : submitLabel || (service ? "Lưu thay đổi" : "Thêm dịch vụ")}
            </Button>
        </form>
    );
}
