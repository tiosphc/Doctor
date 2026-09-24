import * as React from "react";
import type { ReactNode } from "react";
import type { Master, ProductImage } from "@/types/product";
import type { Warehouse } from "@/types/inventory";
import { buttonClass, fieldClass, secondaryButtonClass } from "./ProductAdminShared";
import {
    combinations,
    generateVariants,
    normalizeSku,
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
    createTier: (code: string, name: string) => Promise<boolean>;
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
        <div data-field={name} className={full ? "sm:col-span-2" : ""}>
            <label htmlFor={name} className="mb-1 block text-sm font-medium">
                {label}
            </label>
            {children}
            <p className="min-h-5 pt-1 text-xs text-red-700" role={error ? "alert" : undefined}>
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
                <h2 className="text-xl text-primary">Thông tin cơ bản</h2>
                <p className="text-sm text-muted-foreground">Các trường có dấu * là bắt buộc.</p>
            </div>
            <Field name="name" label="Tên sản phẩm *" error={errors["name"]}>
                <input
                    id="name"
                    value={data.name}
                    maxLength={255}
                    className={input(errors["name"])}
                    onChange={(e) => update({ name: e.target.value })}
                />
            </Field>
            <Field name="sku" label="SKU chính *" error={errors["sku"]}>
                <div className="flex gap-2">
                    <input
                        id="sku"
                        value={data.sku}
                        maxLength={100}
                        className={input(errors["sku"])}
                        onChange={(e) => update({ sku: e.target.value })}
                    />
                    <button
                        type="button"
                        className={secondaryButtonClass}
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
            <Field name="brand_id" label="Thương hiệu" error={errors["brand_id"]}>
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
            <Field name="unit_id" label="Đơn vị mặc định *" error={errors["unit_id"]}>
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
                            onChange={(e) => update({ sellable_retail: e.target.checked })}
                        />
                        Retail
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={data.sellable_dealer}
                            onChange={(e) => update({ sellable_dealer: e.target.checked })}
                        />
                        Đại lý
                    </label>
                </div>
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
                <h2 className="text-xl text-primary">Hình ảnh sản phẩm *</h2>
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
                <h2 className="text-xl text-primary">YouTube Video</h2>
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
                <h2 className="text-xl text-primary">Biến thể sản phẩm</h2>
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
                                                    step="0.001"
                                                    value={variant.initial_stock}
                                                    className={fieldClass}
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

export function PricesStep({ data, update, errors, tiers, busy, createTier }: StepProps) {
    const [tierCode, setTierCode] = React.useState("");
    const [tierName, setTierName] = React.useState("");
    return (
        <div className="space-y-5">
            {data.sellable_retail && (
                <section className="rounded-xl border bg-card p-5">
                    <h2 className="text-xl text-primary">Giá Retail</h2>
                    <div className="mt-4 grid gap-4 sm:grid-cols-2">
                        <Field
                            name="retail_price"
                            label="Giá bán lẻ *"
                            error={errors["retail_price"]}
                        >
                            <input
                                id="retail_price"
                                type="number"
                                min="0.01"
                                step="0.01"
                                className={input(errors["retail_price"])}
                                value={data.retail_price}
                                onChange={(e) => update({ retail_price: e.target.value })}
                                placeholder="120000"
                            />
                            <span className="text-xs text-muted-foreground">
                                {data.retail_price && !Number.isNaN(Number(data.retail_price))
                                    ? `${new Intl.NumberFormat("vi-VN").format(Number(data.retail_price))} ₫`
                                    : "VND"}
                            </span>
                        </Field>
                    </div>
                    <h3 className="mt-4 font-medium">Mức giá theo số lượng</h3>
                    <p className="text-xs text-muted-foreground">
                        Mỗi mức áp dụng từ số lượng nhập đến trước mức tiếp theo.
                    </p>
                    <div className="mt-3 space-y-3">
                        {data.retail_breaks.map((row, index) => (
                            <div
                                key={index}
                                className="grid gap-3 rounded-md border p-3 sm:grid-cols-[1fr_1fr_auto]"
                            >
                                <Field
                                    name={`retail_breaks.${index}.min_quantity`}
                                    label="Từ số lượng *"
                                    error={errors[`retail_breaks.${index}.min_quantity`]}
                                >
                                    <input
                                        id={`retail_breaks.${index}.min_quantity`}
                                        type="number"
                                        min="2"
                                        step="1"
                                        className={input(
                                            errors[`retail_breaks.${index}.min_quantity`],
                                        )}
                                        value={row.min_quantity}
                                        onChange={(e) =>
                                            update({
                                                retail_breaks: data.retail_breaks.map(
                                                    (item, position) =>
                                                        position === index
                                                            ? {
                                                                  ...item,
                                                                  min_quantity: e.target.value,
                                                              }
                                                            : item,
                                                ),
                                            })
                                        }
                                    />
                                </Field>
                                <Field
                                    name={`retail_breaks.${index}.unit_price`}
                                    label="Giá *"
                                    error={errors[`retail_breaks.${index}.unit_price`]}
                                >
                                    <input
                                        id={`retail_breaks.${index}.unit_price`}
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        className={input(
                                            errors[`retail_breaks.${index}.unit_price`],
                                        )}
                                        value={row.unit_price}
                                        onChange={(e) =>
                                            update({
                                                retail_breaks: data.retail_breaks.map(
                                                    (item, position) =>
                                                        position === index
                                                            ? {
                                                                  ...item,
                                                                  unit_price: e.target.value,
                                                              }
                                                            : item,
                                                ),
                                            })
                                        }
                                    />
                                </Field>
                                <button
                                    type="button"
                                    className={`${secondaryButtonClass} self-center`}
                                    onClick={() =>
                                        update({
                                            retail_breaks: data.retail_breaks.filter(
                                                (_, position) => position !== index,
                                            ),
                                        })
                                    }
                                >
                                    Xóa
                                </button>
                            </div>
                        ))}
                    </div>
                    <button
                        type="button"
                        className={secondaryButtonClass}
                        onClick={() =>
                            update({
                                retail_breaks: [
                                    ...data.retail_breaks,
                                    { min_quantity: "", unit_price: "" },
                                ],
                            })
                        }
                    >
                        + Thêm mức giá
                    </button>
                </section>
            )}
            {data.has_variants && data.sellable_retail && (
                <section className="rounded-xl border bg-card p-5">
                    <h2 className="text-xl text-primary">Giá riêng theo biến thể</h2>
                    <p className="text-sm text-muted-foreground">
                        Để trống nếu dùng giá Retail mặc định.
                    </p>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                        {data.variants.map((variant, index) => (
                            <Field
                                key={index}
                                name={`variants.${index}.retail_price_override`}
                                label={Object.values(variant.specifications).join(" / ")}
                                error={errors[`variants.${index}.retail_price_override`]}
                            >
                                <input
                                    id={`variants.${index}.retail_price_override`}
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    className={input(
                                        errors[`variants.${index}.retail_price_override`],
                                    )}
                                    value={variant.retail_price_override}
                                    onChange={(e) =>
                                        update({
                                            variants: data.variants.map((item, position) =>
                                                position === index
                                                    ? {
                                                          ...item,
                                                          retail_price_override: e.target.value,
                                                      }
                                                    : item,
                                            ),
                                        })
                                    }
                                />
                            </Field>
                        ))}
                    </div>
                </section>
            )}
            {data.sellable_dealer && (
                <section className="rounded-xl border bg-card p-5">
                    <h2 className="text-xl text-primary">Giá đại lý & MOQ</h2>
                    <p className="text-sm text-muted-foreground">
                        Giá theo hạng thực tế và ngưỡng số lượng. Chưa có kênh mua Dealer công khai.
                    </p>
                    {!tiers.some((tier) => tier.status === "active") && (
                        <p className="mt-2 text-sm text-amber-700">
                            Chưa có hạng đại lý. Tạo hạng bên dưới trước khi thêm giá.
                        </p>
                    )}
                    <div className="mt-3 space-y-3">
                        {data.dealer_rules.map((row, index) => (
                            <div
                                key={index}
                                className="grid gap-3 rounded-md border p-3 sm:grid-cols-[1fr_1fr_1fr_auto]"
                            >
                                <Field
                                    name={`dealer_rules.${index}.tier_id`}
                                    label="Hạng đại lý *"
                                    error={errors[`dealer_rules.${index}.tier_id`]}
                                >
                                    <select
                                        id={`dealer_rules.${index}.tier_id`}
                                        className={input(errors[`dealer_rules.${index}.tier_id`])}
                                        value={row.tier_id ?? ""}
                                        onChange={(e) =>
                                            update({
                                                dealer_rules: data.dealer_rules.map(
                                                    (item, position) =>
                                                        position === index
                                                            ? {
                                                                  ...item,
                                                                  tier_id:
                                                                      Number(e.target.value) ||
                                                                      null,
                                                              }
                                                            : item,
                                                ),
                                            })
                                        }
                                    >
                                        <option value="">Chọn hạng</option>
                                        {tiers
                                            .filter((tier) => tier.status === "active")
                                            .map((tier) => (
                                                <option key={tier.id} value={tier.id}>
                                                    {tier.name}
                                                </option>
                                            ))}
                                    </select>
                                </Field>
                                <Field
                                    name={`dealer_rules.${index}.min_quantity`}
                                    label="MOQ *"
                                    error={errors[`dealer_rules.${index}.min_quantity`]}
                                >
                                    <input
                                        id={`dealer_rules.${index}.min_quantity`}
                                        type="number"
                                        min="1"
                                        step="1"
                                        className={input(
                                            errors[`dealer_rules.${index}.min_quantity`],
                                        )}
                                        value={row.min_quantity}
                                        onChange={(e) =>
                                            update({
                                                dealer_rules: data.dealer_rules.map(
                                                    (item, position) =>
                                                        position === index
                                                            ? {
                                                                  ...item,
                                                                  min_quantity: e.target.value,
                                                              }
                                                            : item,
                                                ),
                                            })
                                        }
                                    />
                                </Field>
                                <Field
                                    name={`dealer_rules.${index}.unit_price`}
                                    label="Giá *"
                                    error={errors[`dealer_rules.${index}.unit_price`]}
                                >
                                    <input
                                        id={`dealer_rules.${index}.unit_price`}
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        className={input(
                                            errors[`dealer_rules.${index}.unit_price`],
                                        )}
                                        value={row.unit_price}
                                        onChange={(e) =>
                                            update({
                                                dealer_rules: data.dealer_rules.map(
                                                    (item, position) =>
                                                        position === index
                                                            ? {
                                                                  ...item,
                                                                  unit_price: e.target.value,
                                                              }
                                                            : item,
                                                ),
                                            })
                                        }
                                    />
                                </Field>
                                <button
                                    type="button"
                                    className={`${secondaryButtonClass} self-center`}
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
                            </div>
                        ))}
                    </div>
                    <p data-field="dealer_rules" className="text-xs text-red-700">
                        {errors["dealer_rules"]}
                    </p>
                    <button
                        type="button"
                        className={secondaryButtonClass}
                        onClick={() =>
                            update({
                                dealer_rules: [
                                    ...data.dealer_rules,
                                    { tier_id: null, min_quantity: "", unit_price: "" },
                                ],
                            })
                        }
                    >
                        + Thêm mức giá
                    </button>
                    <div className="mt-6 grid gap-3 border-t pt-4 sm:grid-cols-[1fr_1fr_auto]">
                        <input
                            aria-label="Mã hạng mới"
                            className={fieldClass}
                            placeholder="Mã hạng mới"
                            value={tierCode}
                            onChange={(e) => setTierCode(e.target.value)}
                        />
                        <input
                            aria-label="Tên hạng mới"
                            className={fieldClass}
                            placeholder="Tên hạng mới"
                            value={tierName}
                            onChange={(e) => setTierName(e.target.value)}
                        />
                        <button
                            type="button"
                            disabled={busy || !tierCode.trim() || !tierName.trim()}
                            className={secondaryButtonClass}
                            onClick={() =>
                                void createTier(tierCode, tierName).then((created) => {
                                    if (created) {
                                        setTierCode("");
                                        setTierName("");
                                    }
                                })
                            }
                        >
                            Tạo hạng
                        </button>
                    </div>
                </section>
            )}
        </div>
    );
}

export function StockStep({ data, update, errors, units, warehouses }: StepProps) {
    return (
        <div className="space-y-5">
            <section className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <h2 className="text-xl text-primary">Quản lý kho</h2>
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
                            step="0.001"
                            className={input(errors["initial_stock"])}
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
                        step="0.001"
                        className={input(errors["low_stock_threshold"])}
                        value={data.low_stock_threshold}
                        onChange={(e) => update({ low_stock_threshold: e.target.value })}
                    />
                </Field>
                <p className="sm:col-span-2 text-xs text-muted-foreground">
                    Đơn vị đã chọn cho phép{" "}
                    {units.find((unit) => unit.id === data.unit_id)?.decimal_precision ?? 0} chữ số
                    thập phân.{" "}
                    {data.has_variants && "Tồn kho ban đầu nhập theo từng biến thể ở bước trước."}
                </p>
            </section>
            <section className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2">
                <h2 className="sm:col-span-2 text-xl text-primary">Đóng gói / vận chuyển</h2>
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
                            className={input(errors[key])}
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
            <h2 className="text-xl text-primary">Hướng dẫn sử dụng</h2>
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
