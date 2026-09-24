import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { productApi, productKeys } from "@/services/productApi";
import type { Master, Product, ProductVariant } from "@/types/product";
import {
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "./ProductAdminShared";

export function AdminProductsPage() {
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [category, setCategory] = useState("");
    const [brand, setBrand] = useState("");
    const categories = useQuery({
        queryKey: productKeys.masters("categories"),
        queryFn: () => productApi.masters("categories"),
    });
    const brands = useQuery({
        queryKey: productKeys.masters("brands"),
        queryFn: () => productApi.masters("brands"),
    });
    const filters = {
        search,
        category: category ? Number(category) : undefined,
        brand: brand ? Number(brand) : undefined,
        page,
    };
    const query = useQuery({
        queryKey: productKeys.admin(filters),
        queryFn: () => productApi.adminProducts(filters),
    });
    const drafts = useQuery({
        queryKey: ["product-wizard-drafts"],
        queryFn: productApi.wizardDrafts,
    });
    return (
        <ProductAdminGuard>
            <div className="space-y-7">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="label-luxury">Product Master</p>
                        <h1 className="mt-2 text-3xl text-primary">Sản phẩm</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Quản lý mã sản phẩm, SKU và khả năng bán Retail.
                        </p>
                    </div>
                    <Link className={buttonClass} to="/admin/products/new" search={{}}>
                        Thêm sản phẩm
                    </Link>
                </div>
                {drafts.data && drafts.data.data.length > 0 && (
                    <section className="rounded-xl border bg-card p-4">
                        <h2 className="text-lg text-primary">Bản nháp đang làm</h2>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {drafts.data.data.map((item) => (
                                <Link
                                    key={item.id}
                                    to="/admin/products/new"
                                    search={{ draft: item.id }}
                                    className={secondaryButtonClass}
                                >
                                    {item.name || "Sản phẩm chưa đặt tên"} · #{item.id}
                                </Link>
                            ))}
                        </div>
                    </section>
                )}
                <div className="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-3">
                    <input
                        aria-label="Tìm sản phẩm"
                        placeholder="Tên, mã hoặc SKU"
                        className={fieldClass}
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                    />
                    <select
                        aria-label="Lọc danh mục"
                        className={fieldClass}
                        value={category}
                        onChange={(event) => {
                            setCategory(event.target.value);
                            setPage(1);
                        }}
                    >
                        <option value="">Tất cả danh mục</option>
                        {categories.data?.data.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Lọc thương hiệu"
                        className={fieldClass}
                        value={brand}
                        onChange={(event) => {
                            setBrand(event.target.value);
                            setPage(1);
                        }}
                    >
                        <option value="">Tất cả thương hiệu</option>
                        {brands.data?.data.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                </div>
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                ) : query.data.data.length === 0 ? (
                    <EmptyState message="Chưa có sản phẩm phù hợp." />
                ) : (
                    <>
                        <div className="overflow-x-auto rounded-xl border bg-card">
                            <table className="w-full min-w-[620px] text-left text-sm">
                                <thead className="bg-muted/50">
                                    <tr>
                                        <th className="p-4">Mã</th>
                                        <th className="p-4">Sản phẩm</th>
                                        <th className="p-4">Danh mục</th>
                                        <th className="p-4">SKU</th>
                                        <th className="p-4">Trạng thái</th>
                                        <th className="p-4">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {query.data.data.map((item) => (
                                        <tr key={item.id} className="border-t">
                                            <td className="p-4 font-mono">{item.product_code}</td>
                                            <td className="p-4">{item.name}</td>
                                            <td className="p-4">{item.category?.name}</td>
                                            <td className="p-4">{item.variants.length}</td>
                                            <td className="p-4">{item.status}</td>
                                            <td className="p-4">
                                                <Link
                                                    to="/admin/products/$id"
                                                    params={{ id: String(item.id) }}
                                                    className="text-primary underline"
                                                >
                                                    Chi tiết
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination
                            current={query.data.current_page}
                            last={query.data.last_page}
                            onPage={setPage}
                        />
                    </>
                )}
            </div>
        </ProductAdminGuard>
    );
}

export function AdminProductDetailPage({ id }: { id: number }) {
    const query = useQuery({
        queryKey: ["admin-product", id],
        queryFn: () => productApi.adminProduct(id),
    });
    return (
        <ProductAdminGuard>
            {query.isPending ? (
                <LoadingState />
            ) : query.isError ? (
                <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
            ) : (
                <ProductEditor key={id} product={query.data.data} />
            )}
        </ProductAdminGuard>
    );
}

function ProductEditor({ product }: { product: Product }) {
    const client = useQueryClient();
    const [tab, setTab] = useState<"general" | "variants" | "images" | "pricing">("general");
    const [form, setForm] = useState({
        name: product.name,
        slug: product.slug,
        description: product.description || "",
        product_category_id: String(product.product_category_id),
        brand_id: String(product.brand_id || ""),
        status: product.status,
        track_inventory: product.track_inventory || false,
        track_batch: product.track_batch || false,
        track_expiry: product.track_expiry || false,
        default_low_stock_threshold: product.default_low_stock_threshold || "",
    });
    const [variant, setVariant] = useState({
        sku: "",
        variant_name: "",
        unit_id: "",
        sellable_retail: false,
        sellable_dealer: false,
        clinic_material: false,
    });
    const [file, setFile] = useState<File | null>(null);
    const [primary, setPrimary] = useState(false);
    const [imageVariantId, setImageVariantId] = useState("");
    const [imageAlt, setImageAlt] = useState("");
    const [imageSortOrder, setImageSortOrder] = useState("0");
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
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
    const refresh = async () => {
        await client.invalidateQueries({ queryKey: ["admin-product", product.id] });
        await client.invalidateQueries({ queryKey: ["admin-products"] });
    };
    const save = useMutation({
        mutationFn: () =>
            productApi.updateProduct(product.id, {
                ...form,
                product_category_id: Number(form.product_category_id),
                brand_id: form.brand_id ? Number(form.brand_id) : null,
                description: form.description || null,
                default_low_stock_threshold: form.default_low_stock_threshold || null,
            }),
        onSuccess: refresh,
    });
    const addVariant = useMutation({
        mutationFn: () =>
            productApi.createVariant(product.id, { ...variant, unit_id: Number(variant.unit_id) }),
        onSuccess: refresh,
    });
    const upload = useMutation({
        mutationFn: () => {
            const body = new FormData();
            if (file) body.append("image", file);
            body.append("is_primary", primary ? "1" : "0");
            if (imageVariantId) body.append("product_variant_id", imageVariantId);
            if (imageAlt) body.append("alt_text", imageAlt);
            body.append("sort_order", imageSortOrder);
            return productApi.uploadImage(product.id, body);
        },
        onSuccess: refresh,
    });
    const run = async (action: () => Promise<unknown>) => {
        setNotice("");
        setErrors({});
        try {
            await action();
            setNotice("Đã lưu thay đổi.");
        } catch (reason) {
            setNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        }
    };
    return (
        <div className="space-y-6">
            <Link to="/admin/products" className="text-sm text-primary underline">
                ← Danh sách sản phẩm
            </Link>
            <div>
                <p className="label-luxury">{product.product_code}</p>
                <h1 className="mt-2 text-3xl text-primary">{product.name}</h1>
            </div>
            <div className="flex flex-wrap gap-2">
                {(["general", "variants", "images", "pricing"] as const).map((value) => (
                    <button
                        key={value}
                        type="button"
                        className={tab === value ? buttonClass : secondaryButtonClass}
                        onClick={() => setTab(value)}
                    >
                        {
                            {
                                general: "Thông tin",
                                variants: "SKU / Biến thể",
                                images: "Hình ảnh",
                                pricing: "Giá Retail",
                            }[value]
                        }
                    </button>
                ))}
            </div>
            {notice && (
                <p role="status" className="rounded-md border p-3 text-sm">
                    {notice}
                </p>
            )}
            {tab === "general" && (
                <form
                    className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void run(() => save.mutateAsync());
                    }}
                >
                    <label className="text-sm">
                        Tên
                        <input
                            className={fieldClass}
                            value={form.name}
                            onChange={(event) => setForm({ ...form, name: event.target.value })}
                            required
                        />
                    </label>
                    <label className="text-sm">
                        Slug
                        <input
                            className={fieldClass}
                            value={form.slug}
                            onChange={(event) => setForm({ ...form, slug: event.target.value })}
                            required
                        />
                    </label>
                    <label className="text-sm">
                        Danh mục
                        <select
                            className={fieldClass}
                            value={form.product_category_id}
                            onChange={(event) =>
                                setForm({ ...form, product_category_id: event.target.value })
                            }
                        >
                            {categories.data?.data.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm">
                        Thương hiệu
                        <select
                            className={fieldClass}
                            value={form.brand_id}
                            onChange={(event) => setForm({ ...form, brand_id: event.target.value })}
                        >
                            <option value="">Không có</option>
                            {brands.data?.data.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-sm">
                        Trạng thái
                        <select
                            className={fieldClass}
                            value={form.status}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    status: event.target.value as Product["status"],
                                })
                            }
                        >
                            <option value="draft">Bản nháp</option>
                            <option value="active">Hoạt động</option>
                            <option value="inactive">Ngừng hoạt động</option>
                        </select>
                    </label>
                    <label className="sm:col-span-2 text-sm">
                        Mô tả
                        <textarea
                            className={fieldClass}
                            rows={4}
                            value={form.description}
                            onChange={(event) =>
                                setForm({ ...form, description: event.target.value })
                            }
                        />
                    </label>
                    <label className="text-sm">
                        Ngưỡng tồn kho thấp
                        <input
                            type="number"
                            min="0"
                            step="0.001"
                            className={fieldClass}
                            value={form.default_low_stock_threshold}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    default_low_stock_threshold: event.target.value,
                                })
                            }
                        />
                    </label>
                    <div className="sm:col-span-2 flex flex-wrap gap-4 text-sm">
                        {(["track_inventory", "track_batch", "track_expiry"] as const).map(
                            (key) => (
                                <label key={key} className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={form[key]}
                                        onChange={(event) =>
                                            setForm({ ...form, [key]: event.target.checked })
                                        }
                                    />
                                    {
                                        {
                                            track_inventory: "Theo dõi tồn kho",
                                            track_batch: "Theo dõi lô",
                                            track_expiry: "Theo dõi hạn dùng",
                                        }[key]
                                    }
                                </label>
                            ),
                        )}
                    </div>
                    {Object.entries(errors).map(([key, value]) => (
                        <p key={key} className="text-sm text-red-700">
                            {key}: {value}
                        </p>
                    ))}
                    <div className="sm:col-span-2">
                        <button disabled={save.isPending} className={buttonClass}>
                            Lưu sản phẩm
                        </button>
                    </div>
                </form>
            )}
            {tab === "variants" && (
                <div className="space-y-5">
                    <div className="grid gap-3">
                        {product.variants.map((item) => (
                            <VariantRow
                                key={item.id}
                                item={item}
                                productId={product.id}
                                units={units.data?.data || []}
                                onSaved={refresh}
                            />
                        ))}
                    </div>
                    <form
                        className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void run(async () => {
                                await addVariant.mutateAsync();
                                setVariant({
                                    sku: "",
                                    variant_name: "",
                                    unit_id: "",
                                    sellable_retail: false,
                                    sellable_dealer: false,
                                    clinic_material: false,
                                });
                            });
                        }}
                    >
                        <h2 className="sm:col-span-2 text-xl text-primary">Thêm SKU</h2>
                        <label className="text-sm">
                            SKU
                            <input
                                required
                                className={fieldClass}
                                value={variant.sku}
                                onChange={(event) =>
                                    setVariant({ ...variant, sku: event.target.value })
                                }
                            />
                            {errors["sku"] && <span className="text-red-700">{errors["sku"]}</span>}
                        </label>
                        <label className="text-sm">
                            Tên biến thể
                            <input
                                required
                                className={fieldClass}
                                value={variant.variant_name}
                                onChange={(event) =>
                                    setVariant({ ...variant, variant_name: event.target.value })
                                }
                            />
                        </label>
                        <label className="text-sm">
                            Đơn vị
                            <select
                                required
                                className={fieldClass}
                                value={variant.unit_id}
                                onChange={(event) =>
                                    setVariant({ ...variant, unit_id: event.target.value })
                                }
                            >
                                <option value="">Chọn đơn vị</option>
                                {units.data?.data.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="sm:col-span-2 flex flex-wrap gap-4 text-sm">
                            {(
                                ["sellable_retail", "sellable_dealer", "clinic_material"] as const
                            ).map((key) => (
                                <label key={key} className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={variant[key]}
                                        onChange={(event) =>
                                            setVariant({ ...variant, [key]: event.target.checked })
                                        }
                                    />
                                    {
                                        {
                                            sellable_retail: "Bán Retail",
                                            sellable_dealer: "Sẵn sàng Dealer",
                                            clinic_material: "Vật tư phòng khám",
                                        }[key]
                                    }
                                </label>
                            ))}
                        </div>
                        <div className="sm:col-span-2">
                            <button disabled={addVariant.isPending} className={buttonClass}>
                                Thêm SKU
                            </button>
                        </div>
                    </form>
                </div>
            )}
            {tab === "images" && (
                <div className="space-y-5">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {product.images.map((image) => (
                            <div key={image.id} className="rounded-xl border bg-card p-3">
                                <img
                                    src={image.url}
                                    alt={image.alt_text || product.name}
                                    className="aspect-square w-full rounded-md bg-muted object-cover"
                                />
                                <p className="mt-2 text-sm">
                                    {image.is_primary ? "Ảnh chính" : "Ảnh phụ"}
                                </p>
                                {image.product_variant_id && (
                                    <p className="text-xs text-muted-foreground">
                                        SKU:{" "}
                                        {
                                            product.variants.find(
                                                (variant) =>
                                                    variant.id === image.product_variant_id,
                                            )?.sku
                                        }
                                    </p>
                                )}
                                {!image.is_primary && (
                                    <button
                                        type="button"
                                        className="mt-2 mr-4 text-sm text-primary underline"
                                        onClick={() =>
                                            void run(async () => {
                                                await productApi.updateImage(product.id, image.id, {
                                                    is_primary: true,
                                                });
                                                await refresh();
                                            })
                                        }
                                    >
                                        Đặt làm ảnh chính
                                    </button>
                                )}
                                <button
                                    type="button"
                                    className="mt-2 text-sm text-red-700 underline"
                                    onClick={() =>
                                        void run(async () => {
                                            await productApi.deleteImage(product.id, image.id);
                                            await refresh();
                                        })
                                    }
                                >
                                    Gỡ ảnh
                                </button>
                            </div>
                        ))}
                    </div>
                    <form
                        className="space-y-4 rounded-xl border bg-card p-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void run(async () => {
                                await upload.mutateAsync();
                                setFile(null);
                            });
                        }}
                    >
                        <h2 className="text-xl text-primary">Tải ảnh sản phẩm</h2>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            required
                            onChange={(event) => setFile(event.target.files?.[0] || null)}
                        />
                        <label className="block text-sm">
                            SKU áp dụng
                            <select
                                className={fieldClass}
                                value={imageVariantId}
                                onChange={(event) => setImageVariantId(event.target.value)}
                            >
                                <option value="">Toàn sản phẩm</option>
                                {product.variants.map((variant) => (
                                    <option key={variant.id} value={variant.id}>
                                        {variant.sku}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="block text-sm">
                            Mô tả ảnh
                            <input
                                className={fieldClass}
                                value={imageAlt}
                                onChange={(event) => setImageAlt(event.target.value)}
                            />
                        </label>
                        <label className="block text-sm">
                            Thứ tự
                            <input
                                type="number"
                                min="0"
                                className={fieldClass}
                                value={imageSortOrder}
                                onChange={(event) => setImageSortOrder(event.target.value)}
                            />
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={primary}
                                onChange={(event) => setPrimary(event.target.checked)}
                            />
                            Ảnh chính
                        </label>
                        <button disabled={!file || upload.isPending} className={buttonClass}>
                            Tải lên
                        </button>
                    </form>
                </div>
            )}
            {tab === "pricing" && (
                <div className="rounded-xl border bg-card p-6">
                    <h2 className="text-xl text-primary">Giá bán lẻ</h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Giá được quản lý theo bảng giá Retail và từng SKU. Sản phẩm chỉ xuất hiện
                        trên catalog khi có giá Retail hợp lệ.
                    </p>
                    <Link
                        to="/admin/retail-pricing"
                        className="mt-4 inline-block text-primary underline"
                    >
                        Mở bảng giá Retail →
                    </Link>
                </div>
            )}
        </div>
    );
}

function VariantRow({
    item,
    productId,
    units,
    onSaved,
}: {
    item: ProductVariant;
    productId: number;
    units: Master[];
    onSaved: () => Promise<void>;
}) {
    const [error, setError] = useState("");
    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState({
        sku: item.sku,
        variant_name: item.variant_name,
        unit_id: String(item.unit_id),
        track_inventory: item.track_inventory || false,
        track_batch: item.track_batch || false,
        track_expiry: item.track_expiry || false,
    });
    const update = useMutation({
        mutationFn: (body: Record<string, unknown>) =>
            productApi.updateVariant(productId, item.id, body),
        onSuccess: onSaved,
    });
    const change = async (body: Record<string, unknown>) => {
        setError("");
        try {
            await update.mutateAsync(body);
        } catch (reason) {
            setError(errorMessage(reason));
        }
    };
    return (
        <div className="rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="font-mono text-sm font-semibold">{item.sku}</p>
                    <p className="text-sm text-muted-foreground">
                        {item.variant_name} · {item.unit?.name}
                    </p>
                </div>
                <button
                    type="button"
                    className={secondaryButtonClass}
                    onClick={() => setEditing(!editing)}
                >
                    {editing ? "Đóng" : "Sửa SKU"}
                </button>
                <select
                    aria-label={`Trạng thái ${item.sku}`}
                    className={fieldClass + " max-w-44"}
                    value={item.status}
                    onChange={(event) => void change({ status: event.target.value })}
                >
                    <option value="active">Hoạt động</option>
                    <option value="inactive">Ngừng hoạt động</option>
                </select>
            </div>
            {editing && (
                <form
                    className="mt-4 grid gap-3 sm:grid-cols-3"
                    onSubmit={async (event) => {
                        event.preventDefault();
                        await change({ ...form, unit_id: Number(form.unit_id) });
                        setEditing(false);
                    }}
                >
                    <label className="text-sm">
                        SKU
                        <input
                            className={fieldClass}
                            value={form.sku}
                            onChange={(event) => setForm({ ...form, sku: event.target.value })}
                            required
                        />
                    </label>
                    <label className="text-sm">
                        Tên biến thể
                        <input
                            className={fieldClass}
                            value={form.variant_name}
                            onChange={(event) =>
                                setForm({ ...form, variant_name: event.target.value })
                            }
                            required
                        />
                    </label>
                    <label className="text-sm">
                        Đơn vị
                        <select
                            className={fieldClass}
                            value={form.unit_id}
                            onChange={(event) => setForm({ ...form, unit_id: event.target.value })}
                        >
                            {units.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {unit.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="sm:col-span-3 flex flex-wrap gap-4 text-sm">
                        {(["track_inventory", "track_batch", "track_expiry"] as const).map(
                            (key) => (
                                <label key={key} className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={form[key]}
                                        onChange={(event) =>
                                            setForm({ ...form, [key]: event.target.checked })
                                        }
                                    />
                                    {
                                        {
                                            track_inventory: "Theo dõi tồn kho",
                                            track_batch: "Theo dõi lô",
                                            track_expiry: "Theo dõi hạn dùng",
                                        }[key]
                                    }
                                </label>
                            ),
                        )}
                    </div>
                    <div className="sm:col-span-3">
                        <button className={buttonClass} disabled={update.isPending}>
                            Lưu SKU
                        </button>
                    </div>
                </form>
            )}
            <div className="mt-3 flex flex-wrap gap-4 text-sm">
                {(["sellable_retail", "sellable_dealer", "clinic_material"] as const).map((key) => (
                    <label key={key} className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={item[key]}
                            onChange={(event) => void change({ [key]: event.target.checked })}
                        />
                        {
                            {
                                sellable_retail: "Retail",
                                sellable_dealer: "Dealer",
                                clinic_material: "Vật tư",
                            }[key]
                        }
                    </label>
                ))}
            </div>
            {error && (
                <p role="alert" className="mt-2 text-sm text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}
