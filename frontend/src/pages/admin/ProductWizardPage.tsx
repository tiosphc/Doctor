import { useEffect, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate } from "@tanstack/react-router";
import { toast } from "sonner";
import { ErrorState, LoadingState } from "@/components/common/AsyncState";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { inventoryApi } from "@/services/inventoryApi";
import { productApi, productKeys } from "@/services/productApi";
import type { ProductImage } from "@/types/product";
import { ProductAdminGuard, buttonClass, secondaryButtonClass } from "./ProductAdminShared";
import {
    BasicStep,
    ImagesStep,
    InstructionsStep,
    PricesStep,
    StockStep,
    VariantsStep,
    type StepProps,
} from "./ProductWizardSteps";
import {
    emptyWizard,
    normalizeSku,
    payload,
    stepForField,
    steps,
    validateStep,
    type WizardData,
    type WizardErrors,
} from "./productWizard";

export function ProductWizardPage({ draft }: { draft?: number }) {
    const navigate = useNavigate();
    const client = useQueryClient();
    const [data, setData] = useState<WizardData>(emptyWizard);
    const dataRef = useRef(data);
    const [step, setStep] = useState(0);
    const [errors, setErrors] = useState<WizardErrors>({});
    const [images, setImages] = useState<ProductImage[]>([]);
    const [draftId, setDraftId] = useState<number | null>(draft ?? null);
    const draftIdRef = useRef<number | null>(draft ?? null);
    const hydrated = useRef<number | null>(null);
    const wizardKey = useRef<string>(globalThis.crypto.randomUUID());
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState("");
    const busyRef = useRef(false);
    const [notice, setNotice] = useState("");
    const [successId, setSuccessId] = useState<number | null>(null);

    const categories = useQuery({
        queryKey: productKeys.masters("categories"),
        queryFn: () => productApi.masters("categories"),
    });
    const brands = useQuery({
        queryKey: productKeys.masters("brands"),
        queryFn: () => productApi.masters("brands"),
    });
    const units = useQuery({
        queryKey: productKeys.masters("units"),
        queryFn: () => productApi.masters("units"),
    });
    const warehouses = useQuery({
        queryKey: ["wizard-warehouses"],
        queryFn: () => inventoryApi.warehouses({ per_page: 100 }),
    });
    const tiers = useQuery({ queryKey: ["dealer-tiers"], queryFn: productApi.dealerTiers });
    const draftQuery = useQuery({
        queryKey: ["product-wizard-draft", draft],
        queryFn: () => productApi.wizardDraft(draft!),
        enabled: Boolean(draft),
    });

    useEffect(() => {
        if (!draft || !draftQuery.data || hydrated.current === draft) return;
        const product = draftQuery.data.data;
        const restored = { ...emptyWizard, ...(product.wizard_data || {}) } as WizardData;
        dataRef.current = restored;
        setData(restored);
        setImages(product.images || []);
        wizardKey.current = product.wizard_key || wizardKey.current;
        draftIdRef.current = product.id;
        setDraftId(product.id);
        hydrated.current = draft;
        setDirty(false);
    }, [draft, draftQuery.data]);

    useEffect(() => {
        if (!dirty) return;
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = "";
        };
        window.addEventListener("beforeunload", warn);
        return () => window.removeEventListener("beforeunload", warn);
    }, [dirty]);

    const update = (patch: Partial<WizardData>) => {
        const next = { ...dataRef.current, ...patch };
        dataRef.current = next;
        setData(next);
        setDirty(true);
        setErrors({});
        setNotice("");
    };
    const setFieldErrors = (fieldErrors: WizardErrors, targetStep?: number) => {
        setErrors(fieldErrors);
        if (targetStep !== undefined) setStep(targetStep);
        const first = Object.keys(fieldErrors)[0];
        if (first)
            requestAnimationFrame(() => {
                const element = Array.from(
                    document.querySelectorAll<HTMLElement>("[data-field]"),
                ).find((node) => node.dataset["field"] === first);
                element?.scrollIntoView({ behavior: "smooth", block: "center" });
                element
                    ?.querySelector<HTMLElement>("input,select,textarea")
                    ?.focus({ preventScroll: true });
            });
    };
    const serverErrors = (reason: unknown) => {
        setNotice(errorMessage(reason));
        const mapped = Object.fromEntries(
            Object.entries(firstFieldErrors(reason)).map(([key, value]) => [
                key.replace(/^data\./, ""),
                value,
            ]),
        );
        if (Object.keys(mapped).length) {
            const first = Object.keys(mapped)[0];
            setFieldErrors(mapped, stepForField(first!));
        }
    };
    const run = async (label: string, action: () => Promise<void>) => {
        if (busyRef.current) return;
        busyRef.current = true;
        setBusy(label);
        setNotice("");
        try {
            await action();
        } catch (reason) {
            serverErrors(reason);
        } finally {
            busyRef.current = false;
            setBusy("");
        }
    };
    const ensureDraft = async (): Promise<number> => {
        if (draftIdRef.current) return draftIdRef.current;
        const result = await productApi.createWizardDraft({
            wizard_key: wizardKey.current,
            data: payload(dataRef.current),
        });
        const id = result.data.id;
        draftIdRef.current = id;
        hydrated.current = id;
        setDraftId(id);
        await navigate({ to: "/admin/products/new", search: { draft: id }, replace: true });
        return id;
    };
    const saveDraft = () =>
        void run("Đang lưu nháp...", async () => {
            const wasExisting = Boolean(draftIdRef.current);
            const id = await ensureDraft();
            if (wasExisting)
                await productApi.updateWizardDraft(id, {
                    wizard_key: wizardKey.current,
                    data: payload(dataRef.current),
                });
            setDirty(false);
            setNotice("Đã lưu bản nháp. Bạn có thể tiếp tục sau.");
            toast.success("Đã lưu bản nháp.");
            await client.invalidateQueries({ queryKey: ["product-wizard-drafts"] });
        });
    const uploadImages: StepProps["uploadImages"] = async (files) => {
        await run("Đang tải ảnh...", async () => {
            const selected = Array.from(files);
            if (!selected.length) return;
            for (const file of selected) {
                if (
                    !["image/jpeg", "image/png", "image/webp"].includes(file.type) ||
                    file.size > 5 * 1024 * 1024
                ) {
                    setFieldErrors({ images: "Chỉ nhận JPG, PNG hoặc WebP tối đa 5 MB." }, 1);
                    return;
                }
            }
            const id = await ensureDraft();
            const nextSortOrder = Math.max(-1, ...images.map((image) => image.sort_order ?? 0)) + 1;
            for (const [index, file] of selected.entries()) {
                const body = new FormData();
                body.append("image", file);
                body.append("sort_order", String(nextSortOrder + index));
                const result = await productApi.uploadImage(id, body);
                setImages((current) => [...current, result.data]);
            }
            setErrors({});
            setDirty(true);
        });
    };
    const removeImage: StepProps["removeImage"] = async (imageId) => {
        await run("Đang xóa ảnh...", async () => {
            if (!draftIdRef.current) return;
            await productApi.deleteImage(draftIdRef.current, imageId);
            setImages((current) => current.filter((image) => image.id !== imageId));
            setDirty(true);
        });
    };
    const updateImage: StepProps["updateImage"] = async (imageId, patch) => {
        if (!draftIdRef.current) return;
        try {
            const result = await productApi.updateImage(draftIdRef.current, imageId, patch);
            setImages((current) =>
                current.map((image) =>
                    image.id === imageId
                        ? result.data
                        : patch["is_primary"]
                          ? { ...image, is_primary: false }
                          : image,
                ),
            );
            setDirty(true);
        } catch (reason) {
            serverErrors(reason);
        }
    };
    const createTier: StepProps["createTier"] = async (code, name) => {
        try {
            await productApi.createDealerTier({
                code: code.trim().toUpperCase(),
                name: name.trim(),
            });
            await tiers.refetch();
            toast.success("Đã tạo hạng đại lý.");
            return true;
        } catch (reason) {
            setNotice(errorMessage(reason));
            return false;
        }
    };
    const checkSku = async (): Promise<boolean> => {
        const sku = normalizeSku(dataRef.current.sku);
        const result = await productApi.skuAvailability(sku);
        if (!result.available) {
            setFieldErrors({ sku: "SKU này đã tồn tại." }, 0);
            return false;
        }
        return true;
    };
    const goTo = (target: number) =>
        void run("Đang kiểm tra...", async () => {
            if (target <= step) {
                setStep(target);
                setErrors({});
                return;
            }
            for (let index = target === step + 1 ? step : 0; index < target; index++) {
                const precision =
                    units.data?.data.find((unit) => unit.id === dataRef.current.unit_id)
                        ?.decimal_precision ?? 0;
                const found = validateStep(index, dataRef.current, images.length, precision);
                if (Object.keys(found).length) {
                    setFieldErrors(found, index);
                    return;
                }
                if (index === 0 && !(await checkSku())) return;
            }
            setStep(target);
            setErrors({});
            window.scrollTo({ top: 0, behavior: "smooth" });
        });
    const complete = () =>
        void run("Đang tạo sản phẩm...", async () => {
            for (let index = 0; index < steps.length; index++) {
                const precision =
                    units.data?.data.find((unit) => unit.id === dataRef.current.unit_id)
                        ?.decimal_precision ?? 0;
                const found = validateStep(index, dataRef.current, images.length, precision);
                if (Object.keys(found).length) {
                    setFieldErrors(found, index);
                    return;
                }
            }
            if (!(await checkSku())) return;
            const id = await ensureDraft();
            const result = await productApi.completeWizard(id, { data: payload(dataRef.current) });
            setDirty(false);
            setSuccessId(result.data.id);
            toast.success("Tạo sản phẩm thành công.");
            await client.invalidateQueries({ queryKey: ["admin-products"] });
            await client.invalidateQueries({ queryKey: ["product-wizard-drafts"] });
        });

    const props: StepProps = {
        data,
        update,
        errors,
        categories: categories.data?.data || [],
        brands: brands.data?.data || [],
        units: units.data?.data || [],
        warehouses: warehouses.data?.data || [],
        tiers: tiers.data?.data || [],
        images,
        busy: Boolean(busy),
        uploadImages,
        removeImage,
        updateImage,
        createTier,
    };
    return (
        <ProductAdminGuard>
            {draft && draftQuery.isPending ? (
                <LoadingState />
            ) : draft && draftQuery.isError ? (
                <ErrorState
                    message={errorMessage(draftQuery.error)}
                    retry={() => draftQuery.refetch()}
                />
            ) : successId ? (
                <div className="mx-auto max-w-xl space-y-4 rounded-xl border bg-card p-7">
                    <h1 className="text-2xl text-primary">Tạo sản phẩm thành công.</h1>
                    <p>Product, SKU, giá và tồn đầu kỳ đã được lưu.</p>
                    <div className="flex gap-3">
                        <Link
                            to="/admin/products/$id"
                            params={{ id: String(successId) }}
                            className={buttonClass}
                        >
                            Xem sản phẩm
                        </Link>
                        <Link to="/admin/products" className={secondaryButtonClass}>
                            Danh sách sản phẩm
                        </Link>
                    </div>
                </div>
            ) : (
                <div className="mx-auto max-w-6xl space-y-6 pb-24">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="label-luxury">Product Master</p>
                            <h1 className="mt-2 text-3xl text-primary">Thêm sản phẩm</h1>
                            <p className="mt-2 text-sm text-muted-foreground">
                                {draftId ? `Bản nháp #${draftId}` : "Wizard tạo sản phẩm"}
                            </p>
                        </div>
                        <Link
                            to="/admin/products"
                            className={secondaryButtonClass}
                            onClick={(event) => {
                                if (
                                    dirty &&
                                    !window.confirm(
                                        "Bạn có thay đổi chưa được lưu. Bạn có chắc muốn rời khỏi trang?",
                                    )
                                )
                                    event.preventDefault();
                            }}
                        >
                            Về danh sách
                        </Link>
                    </div>
                    <nav
                        aria-label="Các bước tạo sản phẩm"
                        className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6"
                    >
                        {steps.map((label, index) => (
                            <button
                                key={label}
                                type="button"
                                aria-current={index === step ? "step" : undefined}
                                onClick={() => goTo(index)}
                                className={`rounded-lg border px-3 py-3 text-left text-sm ${index === step ? "border-primary bg-primary/10 text-primary" : "bg-card"}`}
                            >
                                <span className="mr-2">
                                    {index < step ? "✓" : index === step ? "●" : "○"}
                                </span>
                                {label}
                            </button>
                        ))}
                    </nav>
                    {notice && (
                        <p
                            role="alert"
                            className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700"
                        >
                            {notice}
                        </p>
                    )}
                    {step === 0 && <BasicStep {...props} />}
                    {step === 1 && <ImagesStep {...props} />}
                    {step === 2 && <VariantsStep {...props} />}
                    {step === 3 && <PricesStep {...props} />}
                    {step === 4 && <StockStep {...props} />}
                    {step === 5 && <InstructionsStep {...props} />}
                    <div className="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card/95 p-4 shadow-lg backdrop-blur">
                        <div>
                            {step > 0 && (
                                <button
                                    type="button"
                                    className={secondaryButtonClass}
                                    disabled={Boolean(busy)}
                                    onClick={() => goTo(step - 1)}
                                >
                                    ← Quay lại
                                </button>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                className={secondaryButtonClass}
                                disabled={Boolean(busy)}
                                onClick={saveDraft}
                            >
                                {busy === "Đang lưu nháp..." ? busy : "Lưu nháp"}
                            </button>
                            <button
                                type="button"
                                className={buttonClass}
                                disabled={Boolean(busy)}
                                onClick={() =>
                                    step === steps.length - 1 ? complete() : goTo(step + 1)
                                }
                            >
                                {busy ||
                                    (step === steps.length - 1
                                        ? "Hoàn tất / Tạo sản phẩm"
                                        : "Tiếp theo →")}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </ProductAdminGuard>
    );
}
