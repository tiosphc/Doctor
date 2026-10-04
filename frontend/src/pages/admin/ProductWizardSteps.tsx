import * as React from "react";
import type { ReactNode } from "react";
import { Link } from "@tanstack/react-router";
import type { Master, ProductImage } from "@/types/product";
import type { Warehouse } from "@/types/inventory";
import { buttonClass, fieldClass, secondaryButtonClass } from "./ProductAdminShared";
import { DealerPriceImportDialog } from "./DealerPriceImportDialog";
import {
    combinations,
    generateVariants,
    normalizeSku,
    validPositiveMoney,
    type WizardData,
    type WizardErrors,
} from "./productWizard";

type Tier = { id: number; code: string; name: string; status: "active" | "inactive" };
export type StepProps = {
    data: WizardData;
    update: (patch: Partial<WizardData>) => void;
    errors: WizardErrors;
    categories: Master[];
    brands: Master[];
    units: Master[];
    warehouses: Warehouse[];
    tiers: Tier[];
    images: ProductImage[];
    busy: boolean;
    uploadImages: (files: FileList | File[]) => Promise<void>;
    removeImage: (id: number) => Promise<void>;
    updateImage: (id: number, patch: Record<string, unknown>) => Promise<void>;
};

function Field({
    label,
    name,
    error,
    children,
    full = false,
}: {
    label: string;
    name: string;
    error?: string | undefined;
    children: ReactNode;
    full?: boolean;
}) {
    return (
        <div
            data-field={name}
            className={`grid min-w-0 content-start gap-1.5 ${full ? "sm:col-span-2" : ""}`}
        >
            {label && (
                <label htmlFor={name} className="admin-form-label">
                    {label}
                </label>
            )}
            {children}
            <p className="min-h-5 text-xs text-red-700" role={error ? "alert" : undefined}>
                {error || "\u00a0"}
            </p>
        </div>
    );
}

const input = (error?: string) =>
    `${fieldClass} ${error ? "border-red-600 focus-visible:outline-red-600" : ""}`;

export function BasicStep({ data, update, errors, categories, brands, units }: StepProps) {
    const selectedCategory = categories.find(
        (category) => category.id === data.product_category_id,
    );
    const parentId = selectedCategory?.parent_id || selectedCategory?.id || null;
    const roots = categories.filter(
        (category) => category.status === "active" && !category.parent_id,
    );
    const children = categories.filter(
        (category) => category.status === "active" && category.parent_id === parentId,
    );
    return (
        <section className="grid gap-x-5 rounded-xl border bg-card p-5 sm:grid-cols-2">
            <div className="mb-5 sm:col-span-2">
                <h2 className="admin-section-title text-primary">Thông tin cơ bản</h2>
                <p className="text-sm text-muted-foreground">Các trường có dấu * là bắt buộc.</p>
            </div>
            <div className="grid gap-x-5 sm:col-span-2 sm:grid-cols-2">
                <Field name="sku" label="SKU chính *" error={errors["sku"]}>
                    <div className="flex gap-2">
                        <input
                            id="sku"
                            value={data.sku}
                            maxLength={100}
                            className={`${input(errors["sku"])} h-10 min-w-0`}
                            onChange={(e) => update({ sku: e.target.value })}
                        />
                        <button
                            type="button"
                            className={`${secondaryButtonClass} h-10 shrink-0 whitespace-nowrap`}
                            onClick={() =>
                                update({
                                    sku: normalizeSku(
                                        data.name
                                            .normalize("NFKD")
                                            .replace(/[\u0300-\u036f]/g, "")
                                            .replace(/[^A-Za-z0-9]+/g, "-")
                                            .replace(/^-|-$/g, ""),
                                    ),
                                })
                            }
                        >
                            Tự tạo
                        </button>
                    </div>
                </Field>
                <Field name="name" label="Tên sản phẩm *" error={errors["name"]}>
                    <input
                        id="name"
                        value={data.name}
                        maxLength={255}
                        className={`${input(errors["name"])} h-10`}
                        onChange={(e) => update({ name: e.target.value })}
                    />
                </Field>
            </div>
            <Field
                name="product_category_id"
                label="Danh mục *"
                error={errors["product_category_id"]}
            >
                <select
                    id="product_category_id"
                    value={parentId ?? ""}
                    className={input(errors["product_category_id"])}
                    onChange={(e) => {
                        const id = Number(e.target.value) || null;
                        update({ product_category_id: id });
                    }}
                >
                    <option value="">Chọn danh mục</option>
                    {roots.map((category) => (
                        <option key={category.id} value={category.id}>
                            {category.name}
                        </option>
                    ))}
                </select>
            </Field>
            <Field name="subcategory" label="Danh mục con" error={errors["subcategory"]}>
                <select
                    id="subcategory"
                    disabled={!children.length}
                    value={
                        children.some((category) => category.id === data.product_category_id)
                            ? data.product_category_id!
                            : ""
                    }
                    className={fieldClass}
                    onChange={(e) =>
                        update({ product_category_id: Number(e.target.value) || parentId })
                    }
                >
                    <option value="">Không có</option>
                    {children.map((category) => (
                        <option key={category.id} value={category.id}>
                            {category.name}
                        </option>
                    ))}
                </select>
            </Field>
            <Field name="brand_id" label="Thương hiệu (không bắt buộc)" error={errors["brand_id"]}>
                <select
                    id="brand_id"
                    value={data.brand_id ?? ""}
                    className={input(errors["brand_id"])}
                    onChange={(e) => update({ brand_id: Number(e.target.value) || null })}
                >
                    <option value="">Không có</option>
                    {brands
                        .filter((brand) => brand.status === "active")
                        .map((brand) => (
                            <option key={brand.id} value={brand.id}>
                                {brand.name}
                            </option>
                        ))}
                </select>
            </Field>
            <Field name="unit_id" label="Đơn vị bán *" error={errors["unit_id"]}>
                <select
                    id="unit_id"
                    value={data.unit_id ?? ""}
                    className={input(errors["unit_id"])}
                    onChange={(e) => update({ unit_id: Number(e.target.value) || null })}
                >
                    <option value="">Chọn đơn vị</option>
                    {units
                        .filter((unit) => unit.status === "active")
                        .map((unit) => (
                            <option key={unit.id} value={unit.id}>
                                {unit.name} ({unit.symbol})
                            </option>
                        ))}
                </select>
            </Field>
            <Field name="channels" label="Kênh bán *" error={errors["channels"]} full>
                <div className="flex flex-wrap gap-5 rounded-md border p-3 text-sm">
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={data.sellable_retail}
                            disabled={data.gift_only}
                            onChange={(e) => update({ sellable_retail: e.target.checked })}
                        />
                        Retail
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={data.sellable_dealer}
                            disabled={data.gift_only}
                            onChange={(e) => update({ sellable_dealer: e.target.checked })}
                        />
                        Đại lý
                    </label>
                </div>
            </Field>
            <Field name="can_be_gift" label="Quà tặng" error={errors["can_be_gift"]} full>
                <div className="grid gap-3 rounded-md border p-3 text-sm sm:grid-cols-2">
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={data.can_be_gift}
                            onChange={(e) =>
                                update({
                                    can_be_gift: e.target.checked,
                                    gift_only: e.target.checked ? data.gift_only : false,
                                })
                            }
                        />
                        Có thể dùng làm quà tặng
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={data.gift_only}
                            onChange={(e) =>
                                update({
                                    gift_only: e.target.checked,
                                    can_be_gift: e.target.checked ? true : data.can_be_gift,
                                    sellable_retail: e.target.checked
                                        ? false
                                        : data.sellable_retail,
                                    sellable_dealer: e.target.checked
                                        ? false
                                        : data.sellable_dealer,
                                    track_inventory: e.target.checked ? true : data.track_inventory,
                                })
                            }
                        />
                        Chỉ dùng làm quà tặng
                    </label>
                </div>
                <p className="text-xs text-muted-foreground">
                    Sản phẩm chỉ tặng không xuất hiện trong catalog bán hàng bình thường.
                </p>
            </Field>
            <Field name="description" label="Mô tả" error={errors["description"]} full>
                <textarea
                    id="description"
                    rows={5}
                    maxLength={20000}
                    className={fieldClass}
                    value={data.description}
                    onChange={(e) => update({ description: e.target.value })}
                />
            </Field>
        </section>
    );
}

export function ImagesStep({
    data,
    update,
    errors,
    images,
    busy,
    uploadImages,
    removeImage,
    updateImage,
}: StepProps) {
    const [draggedId, setDraggedId] = React.useState<number | null>(null);
    const [over, setOver] = React.useState(false);
    const ordered = [...images].sort(
        (a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.id - b.id,
    );
    return (
        <div className="space-y-5">
            <section className="rounded-xl border bg-card p-5">
                <h2 className="admin-section-title text-primary">Hình ảnh sản phẩm *</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    JPG, PNG hoặc WebP, tối đa 5 MB mỗi ảnh. Kéo ảnh để đổi thứ tự.
                </p>
                <div
                    data-field="images"
                    className={`mt-4 rounded-lg border-2 border-dashed p-6 text-center ${over ? "border-primary bg-primary/5" : ""}`}
                    onDragOver={(e) => {
                        e.preventDefault();
                        setOver(true);
                    }}
                    onDragLeave={() => setOver(false)}
                    onDrop={(e) => {
                        e.preventDefault();
                        setOver(false);
                        void uploadImages(e.dataTransfer.files);
                    }}
                >
                    <label className="cursor-pointer text-sm text-primary underline">
                        {busy ? "Đang tải ảnh..." : "Chọn nhiều ảnh hoặc kéo thả vào đây"}
                        <input
                            className="sr-only"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                            disabled={busy}
                            onChange={(e) => {
                                if (e.target.files) void uploadImages(e.target.files);
                                e.target.value = "";
                            }}
                        />
                    </label>
                </div>
                <p
                    className="min-h-5 pt-1 text-xs text-red-700"
                    role={errors["images"] ? "alert" : undefined}
                >
                    {errors["images"] || "\u00a0"}
                </p>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {ordered.map((image, index) => (
                        <div
                            key={image.id}
                            className="rounded-lg border p-2"
                            draggable
                            onDragStart={() => setDraggedId(image.id)}
                            onDragOver={(e) => e.preventDefault()}
                            onDrop={(e) => {
                                e.preventDefault();
                                if (draggedId === null || draggedId === image.id) return;
                                const next = [...ordered];
                                const from = next.findIndex((row) => row.id === draggedId);
                                next.splice(from, 1);
                                const moved = ordered[from];
                                if (!moved) return;
                                next.splice(index, 0, moved);
                                void Promise.all(
                                    next.map((row, position) =>
                                        updateImage(row.id, { sort_order: position }),
                                    ),
                                );
                                setDraggedId(null);
                            }}
                        >
                            <a href={image.url} target="_blank" rel="noreferrer">
                                <img
                                    src={image.url}
                                    alt={image.alt_text || "Ảnh sản phẩm"}
                                    className="aspect-square w-full rounded-md bg-muted object-cover"
                                />
                            </a>
                            <p className="mt-2 text-xs text-muted-foreground">
                                #{index + 1}{" "}
                                {image.is_primary && (
                                    <span className="rounded bg-primary/10 px-2 py-1 text-primary">
                                        Ảnh chính
                                    </span>
                                )}
                            </p>
                            <div className="mt-2 flex gap-3 text-xs">
                                {!image.is_primary && (
                                    <button
                                        type="button"
                                        className="text-primary underline"
                                        disabled={busy}
                                        onClick={() =>
                                            void updateImage(image.id, { is_primary: true })
                                        }
                                    >
                                        Đặt làm ảnh đại diện
                                    </button>
                                )}
                                <button
                                    type="button"
                                    className="text-red-700 underline"
                                    disabled={busy}
                                    onClick={() => void removeImage(image.id)}
                                >
                                    Xóa
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            </section>
            <section className="rounded-xl border bg-card p-5">
                <h2 className="admin-section-title text-primary">YouTube Video</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Không bắt buộc. URL phải thuộc YouTube.
                </p>
                <div className="mt-4 space-y-3">
                    {data.youtube_videos.map((url, index) => (
                        <Field
                            key={index}
                            name={`youtube_videos.${index}`}
                            label={`Video ${index + 1}`}
                            error={errors[`youtube_videos.${index}`]}
                        >
                            <div className="flex gap-2">
                                <input
                                    id={`youtube_videos.${index}`}
                                    className={input(errors[`youtube_videos.${index}`])}
                                    value={url}
                                    placeholder="https://www.youtube.com/watch?v=..."
                                    onChange={(e) =>
                                        update({
                                            youtube_videos: data.youtube_videos.map(
                                                (item, position) =>
                                                    position === index ? e.target.value : item,
                                            ),
                                        })
                                    }
                                />
                                <button
                                    type="button"
                                    className={secondaryButtonClass}
                                    onClick={() =>
                                        update({
                                            youtube_videos: data.youtube_videos.filter(
                                                (_, position) => position !== index,
                                            ),
                                        })
                                    }
                                >
                                    Xóa
                                </button>
                            </div>
                        </Field>
                    ))}
                </div>
                <button
                    type="button"
                    className={secondaryButtonClass}
                    onClick={() => update({ youtube_videos: [...data.youtube_videos, ""] })}
                >
                    + Thêm video
                </button>
            </section>
        </div>
    );
}

export function VariantsStep({ data, update, errors, images }: StepProps) {
    const changeAttribute = (index: number, patch: Partial<WizardData["attributes"][number]>) =>
        update({
            attributes: data.attributes.map((attribute, position) =>
                position === index ? { ...attribute, ...patch } : attribute,
            ),
            variants: [],
        });
    const changeVariant = (index: number, patch: Partial<WizardData["variants"][number]>) =>
        update({
            variants: data.variants.map((variant, position) =>
                position === index ? { ...variant, ...patch } : variant,
            ),
        });
    return (
        <section className="space-y-5 rounded-xl border bg-card p-5">
            <div>
                <h2 className="admin-section-title text-primary">Biến thể sản phẩm</h2>
                <p className="text-sm text-muted-foreground">
                    Sản phẩm không có biến thể sẽ dùng SKU chính.
                </p>
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={data.has_variants}
                    onChange={(e) => update({ has_variants: e.target.checked })}
                />
                Sản phẩm có biến thể
            </label>
            {data.has_variants && (
                <>
                    <div className="space-y-4">
                        {data.attributes.map((attribute, index) => (
                            <div key={index} className="rounded-lg border p-4">
                                <div className="flex items-end gap-3">
                                    <div className="flex-1">
                                        <Field
                                            name={`attributes.${index}.name`}
                                            label={`Tên thuộc tính ${index + 1} *`}
                                            error={errors[`attributes.${index}.name`]}
                                        >
                                            <input
                                                id={`attributes.${index}.name`}
                                                className={input(
                                                    errors[`attributes.${index}.name`],
                                                )}
                                                placeholder="Size, Màu..."
                                                value={attribute.name}
                                                onChange={(e) =>
                                                    changeAttribute(index, { name: e.target.value })
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <button
                                        type="button"
                                        className={`${secondaryButtonClass} mb-6`}
                                        onClick={() =>
                                            update({
                                                attributes: data.attributes.filter(
                                                    (_, position) => position !== index,
                                                ),
                                                variants: [],
                                            })
                                        }
                                    >
                                        Xóa
                                    </button>
                                </div>
                                <div className="grid gap-2 sm:grid-cols-3">
                                    {attribute.values.map((value, valueIndex) => (
                                        <Field
                                            key={valueIndex}
                                            name={`attributes.${index}.values.${valueIndex}`}
                                            label={`Giá trị ${valueIndex + 1} *`}
                                            error={
                                                errors[`attributes.${index}.values.${valueIndex}`]
                                            }
                                        >
                                            <div className="flex gap-1">
                                                <input
                                                    id={`attributes.${index}.values.${valueIndex}`}
                                                    className={input(
                                                        errors[
                                                            `attributes.${index}.values.${valueIndex}`
                                                        ],
                                                    )}
                                                    value={value}
                                                    onChange={(e) =>
                                                        changeAttribute(index, {
                                                            values: attribute.values.map(
                                                                (item, position) =>
                                                                    position === valueIndex
                                                                        ? e.target.value
                                                                        : item,
                                                            ),
                                                        })
                                                    }
                                                />
                                                <button
                                                    type="button"
                                                    className="text-red-700"
                                                    aria-label="Xóa giá trị"
                                                    onClick={() =>
                                                        changeAttribute(index, {
                                                            values: attribute.values.filter(
                                                                (_, position) =>
                                                                    position !== valueIndex,
                                                            ),
                                                        })
                                                    }
                                                >
                                                    ×
                                                </button>
                                            </div>
                                        </Field>
                                    ))}
                                </div>
                                <button
                                    type="button"
                                    className={secondaryButtonClass}
                                    onClick={() =>
                                        changeAttribute(index, {
                                            values: [...attribute.values, ""],
                                        })
                                    }
                                >
                                    + Thêm giá trị
                                </button>
                                <p className="text-xs text-red-700">
                                    {errors[`attributes.${index}.values`]}
                                </p>
                            </div>
                        ))}
                    </div>
                    <button
                        type="button"
                        className={secondaryButtonClass}
                        onClick={() =>
                            update({
                                attributes: [...data.attributes, { name: "", values: [""] }],
                                variants: [],
                            })
                        }
                    >
                        + Thêm thuộc tính
                    </button>
                    <p data-field="attributes" className="text-sm text-red-700">
                        {errors["attributes"]}
                    </p>
                    <div>
                        <button
                            type="button"
                            className={buttonClass}
                            onClick={() => update({ variants: generateVariants(data) })}
                            disabled={
                                !data.attributes.length ||
                                combinations(data.attributes).length > 100
                            }
                        >
                            Tạo biến thể (
                            {data.attributes.length ? combinations(data.attributes).length : 0})
                        </button>
                        <p data-field="variants" className="mt-1 text-sm text-red-700">
                            {errors["variants"]}
                        </p>
                    </div>
                    {!!data.variants.length && (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[720px] text-left text-sm">
                                <thead>
                                    <tr className="border-b">
                                        <th className="p-2">Biến thể</th>
                                        <th className="p-2">SKU *</th>
                                        <th className="p-2">Tồn đầu kỳ</th>
                                        <th className="p-2">Ảnh</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.variants.map((variant, index) => (
                                        <tr
                                            key={JSON.stringify(variant.specifications)}
                                            className="border-b align-top"
                                        >
                                            <td className="p-2">
                                                {Object.values(variant.specifications).join(" / ")}
                                            </td>
                                            <td className="p-2">
                                                <Field
                                                    name={`variants.${index}.sku`}
                                                    label=""
                                                    error={errors[`variants.${index}.sku`]}
                                                >
                                                    <input
                                                        id={`variants.${index}.sku`}
                                                        value={variant.sku}
                                                        className={input(
                                                            errors[`variants.${index}.sku`],
                                                        )}
                                                        onChange={(e) =>
                                                            changeVariant(index, {
                                                                sku: e.target.value,
                                                            })
                                                        }
                                                    />
                                                </Field>
                                            </td>
                                            <td className="p-2">
                                                <input
                                                    aria-label={`Tồn đầu kỳ ${index + 1}`}
                                                    type="number"
                                                    min="0"
                                                    step="1"
                                                    value={variant.initial_stock}
                                                    className={`${fieldClass} max-w-32`}
                                                    onChange={(e) =>
                                                        changeVariant(index, {
                                                            initial_stock: e.target.value,
                                                        })
                                                    }
                                                />
                                                <p className="text-xs text-red-700">
                                                    {errors[`variants.${index}.initial_stock`]}
                                                </p>
                                            </td>
                                            <td className="p-2">
                                                <select
                                                    aria-label={`Ảnh biến thể ${index + 1}`}
                                                    className={fieldClass}
                                                    value={variant.image_id ?? ""}
                                                    onChange={(e) =>
                                                        changeVariant(index, {
                                                            image_id:
                                                                Number(e.target.value) || null,
                                                        })
                                                    }
                                                >
                                                    <option value="">Ảnh sản phẩm chung</option>
                                                    {images.map((image, position) => (
                                                        <option key={image.id} value={image.id}>
                                                            Ảnh #{position + 1}
                                                        </option>
                                                    ))}
                                                </select>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}

export function PricesStep({
    data,
    update,
    errors,
    tiers,
}: Pick<StepProps, "data" | "update" | "errors" | "tiers">) {
    const [importOpen, setImportOpen] = React.useState(false);
    const skuOptions = data.has_variants
        ? data.variants.map((variant) => ({
              sku: normalizeSku(variant.sku),
              name: Object.values(variant.specifications).join(" / ") || variant.sku,
          }))
        : [{ sku: normalizeSku(data.sku), name: "Sản phẩm chính" }];
    const formatPrice = (value: string) =>
        value !== "" && !Number.isNaN(Number(value))
            ? new Intl.NumberFormat("vi-VN").format(Number(value)) + " VND"
            : "VND";
    const changeRule = (index: number, patch: Partial<WizardData["dealer_rules"][number]>) =>
        update({
            dealer_rules: data.dealer_rules.map((rule, position) =>
                position === index ? { ...rule, ...patch } : rule,
            ),
        });
    return (
        <div className="space-y-5">
            {data.sellable_dealer && (
                <DealerPriceImportDialog
                    open={importOpen}
                    onOpenChange={setImportOpen}
                    tiers={tiers}
                    productSkus={skuOptions.map((option) => option.sku)}
                    currentRules={data.dealer_rules}
                    onApply={(dealer_rules) => update({ dealer_rules })}
                />
            )}
            {data.sellable_retail && (
                <section className="rounded-xl border bg-card p-5">
                    <h2 className="admin-section-title text-primary">Giá Retail</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Giá riêng theo biến thể được ưu tiên; để trống sẽ dùng giá bán lẻ mặc định.
                    </p>
                    <div className="mt-4 max-w-sm">
                        <Field
                            name="retail_price"
                            label="Giá bán lẻ mặc định *"
                            error={errors["retail_price"]}
                        >
                            <input
                                id="retail_price"
                                type="number"
                                min="0"
                                step="0.01"
                                className={`${input(errors["retail_price"])} admin-field-medium`}
                                value={data.retail_price}
                                onChange={(event) => update({ retail_price: event.target.value })}
                            />
                            <span className="admin-helper-text">
                                {formatPrice(data.retail_price)}
                            </span>
                        </Field>
                    </div>
                    {data.has_variants && (
                        <>
                            <h3 className="admin-subsection-title mt-4">Giá riêng theo biến thể</h3>
                            <div className="mt-3 grid gap-4 sm:grid-cols-2">
                                {data.variants.map((variant, index) => (
                                    <div
                                        key={variant.sku}
                                        className="min-w-0 rounded-lg border bg-card p-4"
                                    >
                                        <Field
                                            name={"variants." + index + ".retail_price_override"}
                                            label={variant.sku}
                                            error={
                                                errors[
                                                    "variants." + index + ".retail_price_override"
                                                ]
                                            }
                                        >
                                            <input
                                                id={"variants." + index + ".retail_price_override"}
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                className={`${input(errors["variants." + index + ".retail_price_override"])} admin-field-medium`}
                                                value={variant.retail_price_override}
                                                onChange={(event) =>
                                                    update({
                                                        variants: data.variants.map(
                                                            (item, position) =>
                                                                position === index
                                                                    ? {
                                                                          ...item,
                                                                          retail_price_override:
                                                                              event.target.value,
                                                                      }
                                                                    : item,
                                                        ),
                                                    })
                                                }
                                            />
                                            <span className="admin-helper-text">
                                                {variant.retail_price_override
                                                    ? formatPrice(variant.retail_price_override)
                                                    : "Dùng giá mặc định"}
                                            </span>
                                        </Field>
                                        {Object.values(variant.specifications).length > 0 && (
                                            <p className="admin-helper-text mt-1">
                                                {Object.values(variant.specifications).join(" / ")}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </>
                    )}
                </section>
            )}
            {data.sellable_dealer && (
                <section className="rounded-xl border bg-card p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 className="admin-section-title text-primary">Giá Đại lý</h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Tier quyết định giá. MOQ chỉ là số lượng đặt tối thiểu; số lượng lớn
                                hơn vẫn dùng cùng đơn giá.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                className={secondaryButtonClass}
                                onClick={() => setImportOpen(true)}
                            >
                                Import hàng loạt
                            </button>
                            <button
                                type="button"
                                className={secondaryButtonClass}
                                onClick={() =>
                                    update({
                                        dealer_rules: [
                                            ...data.dealer_rules,
                                            {
                                                tier_id: null,
                                                sku: skuOptions[0]?.sku ?? "",
                                                min_quantity: "",
                                                unit_price: "",
                                            },
                                        ],
                                    })
                                }
                            >
                                + Thêm mức giá
                            </button>
                            <button
                                type="button"
                                className={secondaryButtonClass}
                                disabled={!data.dealer_rules.length}
                                onClick={() => update({ dealer_rules: [] })}
                            >
                                Xóa tất cả
                            </button>
                        </div>
                    </div>
                    {!tiers.some((tier) => tier.status === "active") && (
                        <p className="mt-3 text-sm text-amber-700">
                            Chưa có Tier đang hoạt động.{" "}
                            <Link to="/admin/dealer-tiers" className="underline">
                                Cấu hình Tier đại lý
                            </Link>{" "}
                            trước khi thêm giá.
                        </p>
                    )}
                    <p data-field="dealer_rules" className="mt-3 text-sm text-red-700">
                        {errors["dealer_rules"]}
                    </p>
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[700px] text-left text-sm">
                            <thead>
                                <tr className="border-b">
                                    <th className="p-2">Tier</th>
                                    <th className="p-2">Biến thể</th>
                                    <th className="p-2">MOQ</th>
                                    <th className="p-2">Giá Đại lý (VND)</th>
                                    <th className="p-2">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.dealer_rules.map((row, index) => (
                                    <tr key={index} className="border-b align-top">
                                        <td className="p-2">
                                            <select
                                                aria-label={"Tier dòng " + (index + 1)}
                                                className={input(
                                                    errors["dealer_rules." + index + ".tier_id"],
                                                )}
                                                value={row.tier_id ?? ""}
                                                onChange={(event) =>
                                                    changeRule(index, {
                                                        tier_id: Number(event.target.value) || null,
                                                    })
                                                }
                                            >
                                                <option value="">Chọn Tier</option>
                                                {tiers
                                                    .filter((tier) => tier.status === "active")
                                                    .map((tier) => (
                                                        <option key={tier.id} value={tier.id}>
                                                            {tier.name}
                                                        </option>
                                                    ))}
                                            </select>
                                            <p className="text-xs text-red-700">
                                                {errors["dealer_rules." + index + ".tier_id"]}
                                            </p>
                                        </td>
                                        <td className="p-2">
                                            <select
                                                aria-label={"Biến thể dòng " + (index + 1)}
                                                className={input(
                                                    errors["dealer_rules." + index + ".sku"],
                                                )}
                                                value={row.sku}
                                                onChange={(event) =>
                                                    changeRule(index, { sku: event.target.value })
                                                }
                                            >
                                                {skuOptions.map((option) => (
                                                    <option key={option.sku} value={option.sku}>
                                                        {option.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <p className="text-xs text-red-700">
                                                {errors["dealer_rules." + index + ".sku"]}
                                            </p>
                                        </td>
                                        <td className="p-2">
                                            <input
                                                aria-label={"MOQ dòng " + (index + 1)}
                                                type="number"
                                                min="1"
                                                step="1"
                                                className={`${input(errors["dealer_rules." + index + ".min_quantity"])} max-w-28`}
                                                value={row.min_quantity}
                                                onChange={(event) =>
                                                    changeRule(index, {
                                                        min_quantity: event.target.value,
                                                    })
                                                }
                                            />
                                            <p className="text-xs text-red-700">
                                                {errors["dealer_rules." + index + ".min_quantity"]}
                                            </p>
                                        </td>
                                        <td className="p-2">
                                            <input
                                                aria-label={"Giá đại lý dòng " + (index + 1)}
                                                type="number"
                                                min="0.01"
                                                step="0.01"
                                                className={`${input(errors["dealer_rules." + index + ".unit_price"])} max-w-44`}
                                                value={row.unit_price}
                                                onChange={(event) =>
                                                    changeRule(index, {
                                                        unit_price: event.target.value,
                                                    })
                                                }
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {formatPrice(row.unit_price)}
                                            </p>
                                            <p className="text-xs text-red-700">
                                                {errors["dealer_rules." + index + ".unit_price"]}
                                            </p>
                                        </td>
                                        <td className="p-2">
                                            <button
                                                type="button"
                                                className={secondaryButtonClass}
                                                onClick={() =>
                                                    update({
                                                        dealer_rules: data.dealer_rules.filter(
                                                            (_, position) => position !== index,
                                                        ),
                                                    })
                                                }
                                            >
                                                Xóa
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
        </div>
    );
}

export function StockStep({ data, update, errors, warehouses }: StepProps) {
    return (
        <div className="space-y-5">
            <section className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <h2 className="admin-section-title text-primary">Quản lý kho</h2>
                    <p className="text-sm text-muted-foreground">
                        Tồn đầu kỳ được ghi qua Stock Movement khi hoàn tất.
                    </p>
                </div>
                <Field
                    name="track_inventory"
                    label="Theo dõi tồn kho"
                    error={errors["track_inventory"]}
                >
                    <label className="flex items-center gap-2 rounded-md border p-3 text-sm">
                        <input
                            type="checkbox"
                            checked={data.track_inventory}
                            onChange={(e) => update({ track_inventory: e.target.checked })}
                        />
                        Bật theo dõi tồn kho
                    </label>
                </Field>
                <Field
                    name="warehouse_id"
                    label="Kho nhập tồn đầu kỳ"
                    error={errors["warehouse_id"]}
                >
                    <select
                        id="warehouse_id"
                        className={input(errors["warehouse_id"])}
                        value={data.warehouse_id ?? ""}
                        onChange={(e) => update({ warehouse_id: Number(e.target.value) || null })}
                    >
                        <option value="">Chọn kho</option>
                        {warehouses
                            .filter((warehouse) => warehouse.status === "active")
                            .map((warehouse) => (
                                <option key={warehouse.id} value={warehouse.id}>
                                    {warehouse.name}
                                </option>
                            ))}
                    </select>
                </Field>
                {!data.has_variants && (
                    <Field
                        name="initial_stock"
                        label="Tồn kho ban đầu"
                        error={errors["initial_stock"]}
                    >
                        <input
                            id="initial_stock"
                            type="number"
                            min="0"
                            step="1"
                            className={`${input(errors["initial_stock"])} max-w-40`}
                            value={data.initial_stock}
                            onChange={(e) => update({ initial_stock: e.target.value })}
                        />
                    </Field>
                )}
                <Field
                    name="low_stock_threshold"
                    label="Ngưỡng cảnh báo tồn"
                    error={errors["low_stock_threshold"]}
                >
                    <input
                        id="low_stock_threshold"
                        type="number"
                        min="0"
                        step="1"
                        className={`${input(errors["low_stock_threshold"])} max-w-40`}
                        value={data.low_stock_threshold}
                        onChange={(e) => update({ low_stock_threshold: e.target.value })}
                    />
                </Field>
                <p className="sm:col-span-2 text-xs text-muted-foreground">
                    Số lượng tồn kho sử dụng số nguyên.{" "}
                    {data.has_variants && "Tồn kho ban đầu nhập theo từng biến thể ở bước trước."}
                </p>
            </section>
            <section className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2">
                <h2 className="admin-section-title text-primary sm:col-span-2">
                    Đóng gói / vận chuyển
                </h2>
                {(
                    [
                        ["weight", "Trọng lượng (kg)"],
                        ["length", "Chiều dài (cm)"],
                        ["width", "Chiều rộng (cm)"],
                        ["height", "Chiều cao (cm)"],
                    ] as const
                ).map(([key, label]) => (
                    <Field key={key} name={key} label={label} error={errors[key]}>
                        <input
                            id={key}
                            type="number"
                            min="0"
                            step="0.001"
                            className={`${input(errors[key])} max-w-40`}
                            value={data[key]}
                            onChange={(e) => update({ [key]: e.target.value })}
                        />
                    </Field>
                ))}
            </section>
        </div>
    );
}

export function InstructionsStep({ data, update, errors }: StepProps) {
    return (
        <section className="rounded-xl border bg-card p-5">
            <h2 className="admin-section-title text-primary">Hướng dẫn sử dụng</h2>
            <p className="mt-1 text-sm text-muted-foreground">
                Nội dung tùy chọn, lưu dưới dạng văn bản an toàn.
            </p>
            <div className="mt-4">
                <Field
                    name="usage_instructions"
                    label="Hướng dẫn sử dụng"
                    error={errors["usage_instructions"]}
                >
                    <textarea
                        id="usage_instructions"
                        rows={12}
                        maxLength={20000}
                        className={fieldClass}
                        value={data.usage_instructions}
                        onChange={(e) => update({ usage_instructions: e.target.value })}
                    />
                </Field>
            </div>
        </section>
    );
}
