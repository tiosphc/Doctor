import { useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { adminFormLayout } from "@/components/admin/AdminFormLayout";
import { Link, useNavigate } from "@tanstack/react-router";
import { Ellipsis, Eye, Filter, Pencil, Power, Tag, Trash2 } from "lucide-react";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { formatProductQuantity, isNonNegativeProductQuantity } from "@/lib/productQuantity";
import { productApi, productKeys, type ProductPricingData } from "@/services/productApi";
import type { Product, ProductVariant } from "@/types/product";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Button } from "@/components/ui/button";
import { PricesStep } from "./ProductWizardSteps";
import {
    emptyWizard,
    generateVariantSku,
    validateStep,
    type WizardData,
    type WizardErrors,
} from "./productWizard";
import {
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "./ProductAdminShared";

const money = (value: string) =>
    new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(Number(value));

function getRetailPriceSummary(product: Product): string | null {
    if (product.gift_only) {
        return null;
    }
    const prices = product.variants
        .map((v) => v.retail_price?.unit_price)
        .filter((p): p is string => p !== undefined && p !== null)
        .map(Number)
        .filter((n) => n > 0);
    if (prices.length === 0) {
        return null;
    }
    const min = Math.min(...prices);
    if (prices.length > 1 && Math.max(...prices) !== min) {
        return `từ ${money(String(min))}`;
    }
    return money(String(min));
}

function getTypeLabel(product: Product): string {
    if (product.gift_only) {
        return "Chỉ quà tặng";
    }
    if (product.can_be_gift) {
        return "Bán + Quà";
    }
    return "Bán hàng";
}

function getStatusBadge(status: Product["status"]): { label: string; className: string } {
    switch (status) {
        case "active":
            return {
                label: "ACTIVE",
                className: "bg-green-600/90 text-white",
            };
        case "inactive":
            return {
                label: "INACTIVE",
                className: "bg-red-600/90 text-white",
            };
        case "draft":
            return {
                label: "DRAFT",
                className: "bg-amber-500/90 text-white",
            };
    }
}

function SkeletonGrid() {
    return (
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {Array.from({ length: 8 }).map((_, i) => (
                <div key={i} className="animate-pulse rounded-xl border bg-card">
                    <div className="aspect-[4/3] rounded-t-xl bg-muted" />
                    <div className="space-y-3 p-4">
                        <div className="h-4 w-16 rounded bg-muted" />
                        <div className="h-3 w-24 rounded bg-muted" />
                        <div className="h-5 w-3/4 rounded bg-muted" />
                        <div className="h-3 w-20 rounded bg-muted" />
                        <div className="h-3 w-28 rounded bg-muted" />
                    </div>
                    <div className="flex gap-2 border-t p-3">
                        <div className="h-8 grow rounded bg-muted" />
                        <div className="h-8 w-8 rounded bg-muted" />
                    </div>
                </div>
            ))}
        </div>
    );
}

function AdminProductCard({
    product,
    onStatusToggled,
}: {
    product: Product;
    onStatusToggled: () => void;
}) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [statusConfirmationOpen, setStatusConfirmationOpen] = useState(false);
    const [deleteConfirmationOpen, setDeleteConfirmationOpen] = useState(false);
    const image =
        product.images.find((img) => img.is_primary) ||
        product.images.find((img) => img.product_variant_id === null) ||
        product.images[0];
    const imageUrl = image?.url || image?.path;
    const retailSummary = getRetailPriceSummary(product);
    const unitLabels = [
        ...new Set(product.variants.map((variant) => variant.unit?.symbol).filter(Boolean)),
    ];
    const typeLabel = getTypeLabel(product);
    const statusBadge = getStatusBadge(product.status);

    const toggleStatus = useMutation({
        mutationFn: () => {
            const newStatus = product.status === "active" ? "inactive" : "active";
            return productApi.updateProduct(product.id, { status: newStatus });
        },
        onSuccess: () => {
            setStatusConfirmationOpen(false);
            toast.success(
                product.status === "active"
                    ? "Đã ngừng hoạt động sản phẩm."
                    : "Đã kích hoạt sản phẩm.",
            );
            queryClient.invalidateQueries({ queryKey: ["admin-products"] });
            onStatusToggled();
        },
        onError: (reason) => toast.error(errorMessage(reason)),
    });
    const deleteProduct = useMutation({
        mutationFn: () => productApi.deleteProduct(product.id),
        onSuccess: () => {
            setDeleteConfirmationOpen(false);
            toast.success("Đã xóa sản phẩm.");
            void queryClient.invalidateQueries({ queryKey: ["admin-products"] });
            void queryClient.invalidateQueries({ queryKey: ["products"] });
        },
        onError: (reason) => toast.error(errorMessage(reason)),
    });

    const goToDetail = () => {
        if (product.status === "draft") {
            navigate({ to: "/admin/products/new", search: { draft: product.id } });
            return;
        }
        navigate({ to: "/admin/products/$id", params: { id: String(product.id) } });
    };

    return (
        <div className="group flex flex-col overflow-hidden rounded-xl border bg-card transition-shadow hover:shadow-lg">
            {/* Image section */}
            <button
                type="button"
                className="relative aspect-[4/3] cursor-pointer overflow-hidden bg-muted"
                onClick={goToDetail}
                aria-label={`Xem chi tiết ${product.name}`}
            >
                {imageUrl ? (
                    <img
                        src={imageUrl}
                        alt={image?.alt_text || product.name}
                        className="size-full object-cover transition-transform duration-300 group-hover:scale-105"
                    />
                ) : (
                    <div className="flex size-full items-center justify-center text-muted-foreground">
                        <Tag size={40} strokeWidth={1} />
                    </div>
                )}
                {/* Status badge */}
                <span
                    className={`absolute right-2 top-2 rounded-md px-2 py-0.5 text-[10px] font-bold tracking-wider ${statusBadge.className}`}
                >
                    {statusBadge.label}
                </span>
            </button>

            {/* Info section */}
            <div className="flex grow flex-col p-4">
                {/* Type badge */}
                <span
                    className={`inline-flex w-fit items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${
                        product.gift_only
                            ? "bg-purple-100 text-purple-800"
                            : product.can_be_gift
                              ? "bg-amber-100 text-amber-800"
                              : "bg-blue-100 text-blue-800"
                    }`}
                >
                    {typeLabel}
                </span>

                {/* Category */}
                {product.category?.name && (
                    <p className="mt-2 text-xs text-muted-foreground">{product.category.name}</p>
                )}

                {/* Product name — clickable */}
                <button
                    type="button"
                    className="mt-1 text-left text-sm font-semibold text-primary transition-colors hover:underline"
                    onClick={goToDetail}
                >
                    {product.name}
                </button>

                {/* Product code */}
                <p className="mt-1 font-mono text-xs text-muted-foreground">
                    {product.product_code}
                </p>

                {/* SKU count */}
                <p className="mt-1 text-xs text-muted-foreground">{product.variants.length} SKU</p>
                {unitLabels.length > 0 && (
                    <p className="mt-1 text-xs text-muted-foreground">
                        Đơn vị: {unitLabels.join(", ")}
                    </p>
                )}

                {/* Pricing */}
                {(product.gift_only || retailSummary) && (
                    <div className="mt-auto pt-3">
                        {product.gift_only ? (
                            <p className="text-xs font-medium text-purple-700">
                                Không bán trực tiếp
                            </p>
                        ) : (
                            <p className="text-sm font-semibold text-primary">
                                Retail: {retailSummary}
                            </p>
                        )}
                    </div>
                )}
            </div>

            {/* Actions footer */}
            <div className="flex items-center gap-2 border-t px-3 py-2">
                {product.status === "draft" ? (
                    <button
                        type="button"
                        className="inline-flex grow items-center justify-center gap-1.5 rounded-md bg-red-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-800"
                        onClick={() => setDeleteConfirmationOpen(true)}
                    >
                        <Trash2 size={13} />
                        Xóa bản nháp
                    </button>
                ) : (
                    <Link
                        to="/admin/products/$id"
                        params={{ id: String(product.id) }}
                        className="inline-flex grow items-center justify-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground transition-colors hover:bg-navy-deep"
                    >
                        <Pencil size={13} />
                        Chỉnh sửa
                    </Link>
                )}
                {product.status !== "draft" && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="inline-flex items-center justify-center rounded-md border px-2 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                aria-label="Thêm thao tác"
                            >
                                <Ellipsis size={16} />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                            <DropdownMenuItem className="cursor-pointer" onSelect={goToDetail}>
                                <Eye size={14} />
                                Xem chi tiết
                            </DropdownMenuItem>
                            <DropdownMenuItem className="cursor-pointer" onSelect={goToDetail}>
                                <Pencil size={14} />
                                Chỉnh sửa
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                className="cursor-pointer"
                                onSelect={() =>
                                    navigate({
                                        to: "/admin/products/$id",
                                        params: { id: String(product.id) },
                                    })
                                }
                            >
                                <Tag size={14} />
                                Quản lý giá
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                className={`cursor-pointer ${
                                    product.status === "active"
                                        ? "text-red-600 focus:text-red-600"
                                        : "text-green-600 focus:text-green-600"
                                }`}
                                onSelect={() => setStatusConfirmationOpen(true)}
                                disabled={toggleStatus.isPending}
                            >
                                <Power size={14} />
                                {product.status === "active" ? "Ngừng hoạt động" : "Kích hoạt"}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                className="cursor-pointer text-red-600 focus:text-red-600"
                                onSelect={() => setDeleteConfirmationOpen(true)}
                            >
                                <Trash2 size={14} />
                                Xóa sản phẩm
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
            <AlertDialog
                open={statusConfirmationOpen}
                onOpenChange={(open) => {
                    if (!open && toggleStatus.isPending) return;
                    setStatusConfirmationOpen(open);
                }}
            >
                <AlertDialogContent className="w-[calc(100%-2rem)] max-w-md rounded-xl">
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {product.status === "active"
                                ? "Ngừng hoạt động sản phẩm?"
                                : "Kích hoạt sản phẩm?"}
                        </AlertDialogTitle>
                        <AlertDialogDescription className="leading-6">
                            Bạn có chắc muốn{" "}
                            {product.status === "active" ? "ngừng hoạt động" : "kích hoạt"} sản phẩm{" "}
                            <strong className="font-semibold text-foreground">
                                {product.name}
                            </strong>
                            ?
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter className="gap-2">
                        <AlertDialogCancel disabled={toggleStatus.isPending}>Hủy</AlertDialogCancel>
                        <Button
                            type="button"
                            variant={product.status === "active" ? "destructive" : "default"}
                            disabled={toggleStatus.isPending}
                            onClick={() => toggleStatus.mutate()}
                        >
                            {toggleStatus.isPending
                                ? "Đang xử lý..."
                                : product.status === "active"
                                  ? "Xác nhận ngừng hoạt động"
                                  : "Xác nhận kích hoạt"}
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <AlertDialog
                open={deleteConfirmationOpen}
                onOpenChange={(open) => {
                    if (!open && deleteProduct.isPending) return;
                    setDeleteConfirmationOpen(open);
                }}
            >
                <AlertDialogContent className="w-[calc(100%-2rem)] max-w-md rounded-xl">
                    <AlertDialogHeader>
                        <AlertDialogTitle>Xóa sản phẩm?</AlertDialogTitle>
                        <AlertDialogDescription className="leading-6">
                            Xóa vĩnh viễn{" "}
                            <strong className="font-semibold text-foreground">
                                {product.name}
                            </strong>
                            ? Sản phẩm có lịch sử giao dịch hoặc tồn kho sẽ không thể xóa.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter className="gap-2">
                        <AlertDialogCancel disabled={deleteProduct.isPending}>
                            Hủy
                        </AlertDialogCancel>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={deleteProduct.isPending}
                            onClick={() => deleteProduct.mutate()}
                        >
                            {deleteProduct.isPending ? "Đang xóa..." : "Xác nhận xóa"}
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}

export function AdminProductsPage() {
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [category, setCategory] = useState("");
    const [brand, setBrand] = useState("");
    const [statusFilter, setStatusFilter] = useState("");
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [giftFilter, setGiftFilter] = useState<"all" | "gift_capable" | "gift_only" | "normal">(
        "all",
    );
    const queryClient = useQueryClient();
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
        gift_filter: giftFilter,
        status: statusFilter || undefined,
        page,
    };
    const query = useQuery({
        queryKey: productKeys.admin(filters),
        queryFn: () => productApi.adminProducts(filters),
    });

    const hasActiveFilters = category || brand || giftFilter !== "all" || statusFilter;

    const filterControls = (
        <>
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
            <select
                aria-label="Lọc loại sản phẩm"
                className={fieldClass}
                value={giftFilter}
                onChange={(event) => {
                    setGiftFilter(event.target.value as typeof giftFilter);
                    setPage(1);
                }}
            >
                <option value="all">Tất cả sản phẩm</option>
                <option value="gift_capable">Có thể làm quà</option>
                <option value="gift_only">Chỉ quà tặng</option>
                <option value="normal">Bán bình thường</option>
            </select>
            <select
                aria-label="Lọc trạng thái"
                className={fieldClass}
                value={statusFilter}
                onChange={(event) => {
                    setStatusFilter(event.target.value);
                    setPage(1);
                }}
            >
                <option value="">Tất cả trạng thái</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </>
    );

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
                {/* Filter toolbar */}
                <div className="rounded-xl border bg-card p-4">
                    {/* Search + mobile filter toggle */}
                    <div className="flex gap-3">
                        <input
                            aria-label="Tìm sản phẩm"
                            placeholder="Tên, mã hoặc SKU"
                            className={`${fieldClass} grow`}
                            value={search}
                            onChange={(event) => {
                                setSearch(event.target.value);
                                setPage(1);
                            }}
                        />
                        <button
                            type="button"
                            className={`inline-flex items-center gap-1.5 sm:hidden ${secondaryButtonClass} ${hasActiveFilters ? "border-primary text-primary" : ""}`}
                            onClick={() => setFiltersOpen(!filtersOpen)}
                        >
                            <Filter size={14} />
                            Bộ lọc
                        </button>
                    </div>
                    {/* Desktop filters — always visible */}
                    <div className="mt-3 hidden gap-3 sm:grid sm:grid-cols-2 lg:grid-cols-4">
                        {filterControls}
                    </div>
                    {/* Mobile filters — toggleable */}
                    {filtersOpen && (
                        <div className="mt-3 grid gap-3 sm:hidden">{filterControls}</div>
                    )}
                </div>

                {/* Product grid */}
                {query.isPending ? (
                    <SkeletonGrid />
                ) : query.isError ? (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                ) : query.data.data.length === 0 ? (
                    <EmptyState
                        message={
                            search || hasActiveFilters
                                ? "Không tìm thấy sản phẩm phù hợp với bộ lọc."
                                : "Chưa có sản phẩm nào."
                        }
                    />
                ) : (
                    <>
                        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {query.data.data.map((item) => (
                                <AdminProductCard
                                    key={item.id}
                                    product={item}
                                    onStatusToggled={() =>
                                        queryClient.invalidateQueries({
                                            queryKey: ["admin-products"],
                                        })
                                    }
                                />
                            ))}
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
    const productUnit = [...product.variants].sort((first, second) => first.id - second.id)[0]?.unit
        ?.name;
    const [tab, setTab] = useState<"general" | "variants" | "images" | "pricing">("general");
    const [form, setForm] = useState({
        name: product.name,
        slug: product.slug,
        description: product.description || "",
        product_category_id: String(product.product_category_id),
        brand_id: String(product.brand_id || ""),
        status: product.status,
        track_inventory: product.track_inventory || false,
        can_be_gift: product.can_be_gift || false,
        gift_only: product.gift_only || false,
        track_batch: product.track_batch || false,
        track_expiry: product.track_expiry || false,
        default_low_stock_threshold: product.default_low_stock_threshold
            ? formatProductQuantity(product.default_low_stock_threshold)
            : "",
    });
    const [variantName, setVariantName] = useState("");
    const [file, setFile] = useState<File | null>(null);
    const [primary, setPrimary] = useState(false);
    const [imageVariantId, setImageVariantId] = useState("");
    const [imageAlt, setImageAlt] = useState("");
    const [imageSortOrder, setImageSortOrder] = useState("0");
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const actionPending = useRef(false);
    const [running, setRunning] = useState(false);
    const categories = useQuery({
        queryKey: productKeys.masters("categories"),
        queryFn: () => productApi.masters("categories"),
    });
    const brands = useQuery({
        queryKey: productKeys.masters("brands"),
        queryFn: () => productApi.masters("brands"),
    });
    const refresh = async () => {
        await client.invalidateQueries({ queryKey: ["admin-product", product.id] });
        await client.invalidateQueries({ queryKey: ["admin-products"] });
        await client.invalidateQueries({ queryKey: ["products"] });
        await client.invalidateQueries({ queryKey: ["product", product.slug] });
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
            productApi.createVariant(product.id, { variant_name: variantName.trim() }),
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
    const run = async (action: () => Promise<unknown>, successMessage: string) => {
        if (actionPending.current) return;
        actionPending.current = true;
        setRunning(true);
        setNotice("");
        setErrors({});
        try {
            await action();
            toast.success(successMessage);
        } catch (reason) {
            setNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
            toast.error(errorMessage(reason));
        } finally {
            actionPending.current = false;
            setRunning(false);
        }
    };
    return (
        <div className={`${adminFormLayout.complex} space-y-6`}>
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
                                pricing: "Giá",
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
                        if (
                            form.default_low_stock_threshold !== "" &&
                            !isNonNegativeProductQuantity(form.default_low_stock_threshold)
                        ) {
                            setNotice("Ngưỡng tồn kho phải là số nguyên không âm.");
                            return;
                        }
                        if (
                            product.status === "active" &&
                            form.status === "inactive" &&
                            !window.confirm(`Ngừng hoạt động sản phẩm "${product.name}"?`)
                        )
                            return;
                        void run(
                            () => save.mutateAsync(),
                            product.status === "active" && form.status === "inactive"
                                ? "Đã ngừng hoạt động sản phẩm."
                                : product.status === "inactive" && form.status === "active"
                                  ? "Đã kích hoạt sản phẩm."
                                  : "Cập nhật sản phẩm thành công.",
                        );
                    }}
                >
                    <label className="admin-form-field admin-form-label">
                        SKU chính
                        <input
                            className={`${fieldClass} cursor-not-allowed opacity-70`}
                            value={product.base_sku || product.variants[0]?.sku || ""}
                            disabled
                        />
                    </label>
                    <label className="admin-form-field admin-form-label">
                        Tên
                        <input
                            className={fieldClass}
                            value={form.name}
                            onChange={(event) => setForm({ ...form, name: event.target.value })}
                            required
                        />
                    </label>
                    <label className="admin-form-field admin-form-label">
                        Slug
                        <input
                            className={fieldClass}
                            value={form.slug}
                            onChange={(event) => setForm({ ...form, slug: event.target.value })}
                            required
                        />
                    </label>
                    <label className="admin-form-field admin-form-label">
                        Danh mục
                        <select
                            className={`${fieldClass} admin-field-medium`}
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
                    <label className="admin-form-field admin-form-label">
                        Thương hiệu
                        <select
                            className={`${fieldClass} admin-field-medium`}
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
                    <label className="admin-form-field admin-form-label">
                        Trạng thái
                        <select
                            className={`${fieldClass} admin-field-medium`}
                            value={form.status}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    status: event.target.value as Product["status"],
                                })
                            }
                        >
                            <option value="active">Hoạt động</option>
                            <option value="inactive">Ngừng hoạt động</option>
                        </select>
                    </label>
                    <label className="admin-form-field admin-form-label sm:col-span-2">
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
                    <label className="admin-form-field admin-form-label">
                        Ngưỡng tồn kho thấp
                        <input
                            type="number"
                            min="0"
                            step="1"
                            className={`${fieldClass} admin-field-compact`}
                            value={form.default_low_stock_threshold}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    default_low_stock_threshold: event.target.value,
                                })
                            }
                        />
                    </label>
                    <div className="sm:col-span-2 flex flex-wrap gap-4 pt-1 text-sm">
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.can_be_gift}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        can_be_gift: event.target.checked,
                                        gift_only: event.target.checked ? form.gift_only : false,
                                    })
                                }
                            />
                            Có thể dùng làm quà tặng
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.gift_only}
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        gift_only: event.target.checked,
                                        can_be_gift: event.target.checked ? true : form.can_be_gift,
                                        track_inventory: event.target.checked
                                            ? true
                                            : form.track_inventory,
                                    })
                                }
                            />
                            Chỉ dùng làm quà tặng
                        </label>
                        {form.gift_only && (
                            <span className="basis-full text-xs text-muted-foreground">
                                Sản phẩm không xuất hiện trong catalog bán hàng bình thường.
                            </span>
                        )}
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
                    <div className="flex justify-end sm:col-span-2">
                        <button disabled={save.isPending || running} className={buttonClass}>
                            {save.isPending || running ? "Đang lưu..." : "Lưu sản phẩm"}
                        </button>
                    </div>
                </form>
            )}
            {tab === "variants" && (
                <div className="space-y-5">
                    <p className="text-sm text-muted-foreground">
                        Đơn vị của sản phẩm: {productUnit || "Chưa có đơn vị"}. Biến thể mới sẽ dùng
                        cùng đơn vị này. Sau khi thêm, thiết lập giá ở tab Giá và nhập tồn kho nếu
                        cần.
                    </p>
                    <div className="grid gap-3">
                        {product.variants.map((item) => (
                            <VariantRow
                                key={item.id}
                                item={item}
                                productId={product.id}
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
                                setVariantName("");
                            }, "Thêm biến thể thành công.");
                        }}
                    >
                        <h2 className="admin-section-title text-primary sm:col-span-2">
                            Thêm biến thể
                        </h2>
                        <label className="admin-form-field admin-form-label">
                            Tên biến thể
                            <input
                                required
                                className={fieldClass}
                                placeholder="Ví dụ: 5ml"
                                value={variantName}
                                onChange={(event) => setVariantName(event.target.value)}
                            />
                            {errors["variant_name"] && (
                                <span className="text-red-700">{errors["variant_name"]}</span>
                            )}
                        </label>
                        <div className="grid content-start gap-1.5">
                            <span className="admin-form-label">SKU tự tạo</span>
                            <span className="flex min-h-10 items-center break-all rounded-md border bg-muted px-3 font-mono text-sm text-primary">
                                {generateVariantSku(
                                    product.base_sku || product.product_code,
                                    variantName,
                                ) || "Nhập tên biến thể"}
                            </span>
                            {errors["sku"] && (
                                <span className="text-xs text-red-700">{errors["sku"]}</span>
                            )}
                        </div>
                        <div className="flex justify-end sm:col-span-2">
                            <button
                                disabled={addVariant.isPending || running}
                                className={buttonClass}
                            >
                                {addVariant.isPending || running ? "Đang thêm..." : "Thêm biến thể"}
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
                                        disabled={running}
                                        className="mt-2 mr-4 text-sm text-primary underline"
                                        onClick={() =>
                                            void run(async () => {
                                                await productApi.updateImage(product.id, image.id, {
                                                    is_primary: true,
                                                });
                                                await refresh();
                                            }, "Đã chọn ảnh đại diện sản phẩm.")
                                        }
                                    >
                                        {running ? "Đang cập nhật..." : "Đặt làm ảnh chính"}
                                    </button>
                                )}
                                <button
                                    type="button"
                                    disabled={running}
                                    className="mt-2 text-sm text-red-700 underline"
                                    onClick={() =>
                                        window.confirm("Gỡ ảnh sản phẩm này?") &&
                                        void run(async () => {
                                            await productApi.deleteImage(product.id, image.id);
                                            await refresh();
                                        }, "Đã gỡ ảnh sản phẩm.")
                                    }
                                >
                                    {running ? "Đang xử lý..." : "Gỡ ảnh"}
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
                            }, "Tải ảnh sản phẩm thành công.");
                        }}
                    >
                        <h2 className="text-xl text-primary">Tải ảnh sản phẩm</h2>
                        <label className="admin-form-field admin-form-label">
                            Tệp ảnh
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                required
                                onChange={(event) => setFile(event.target.files?.[0] || null)}
                            />
                        </label>
                        <label className="admin-form-field admin-form-label">
                            SKU áp dụng
                            <select
                                className={`${fieldClass} admin-field-medium`}
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
                        <label className="admin-form-field admin-form-label">
                            Mô tả ảnh
                            <input
                                className={fieldClass}
                                value={imageAlt}
                                onChange={(event) => setImageAlt(event.target.value)}
                            />
                        </label>
                        <label className="admin-form-field admin-form-label">
                            Thứ tự
                            <input
                                type="number"
                                min="0"
                                className={`${fieldClass} admin-field-compact`}
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
                        <div className="flex justify-end">
                            <button
                                disabled={!file || upload.isPending || running}
                                className={buttonClass}
                            >
                                {upload.isPending || running ? "Đang tải..." : "Tải lên"}
                            </button>
                        </div>
                    </form>
                </div>
            )}
            {tab === "pricing" && <ProductPricingEditor product={product} onSaved={refresh} />}
        </div>
    );
}

function ProductPricingEditor({
    product,
    onSaved,
}: {
    product: Product;
    onSaved: () => Promise<void>;
}) {
    const query = useQuery({
        queryKey: ["admin-product-pricing", product.id],
        queryFn: () => productApi.productPricing(product.id),
        staleTime: 0,
    });
    const tiers = useQuery({ queryKey: ["dealer-tiers"], queryFn: productApi.dealerTiers });
    if (query.isPending) return <LoadingState />;
    if (query.isError)
        return <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />;
    return (
        <ProductPricingForm
            key={JSON.stringify(query.data.data)}
            product={product}
            pricing={query.data.data}
            tiers={tiers.data?.data ?? []}
            onSaved={async () => {
                await query.refetch();
                await onSaved();
            }}
        />
    );
}

function ProductPricingForm({
    product,
    pricing,
    tiers,
    onSaved,
}: {
    product: Product;
    pricing: ProductPricingData;
    tiers: Array<{ id: number; code: string; name: string; status: "active" | "inactive" }>;
    onSaved: () => Promise<void>;
}) {
    const [data, setData] = useState<WizardData>({
        ...emptyWizard,
        name: product.name,
        sku: product.variants[0]?.sku ?? "",
        has_variants: product.variants.length > 1,
        variants: product.variants.map((variant) => ({
            sku: variant.sku,
            specifications: variant.specifications ?? {},
            image_id: null,
            retail_price_override:
                pricing.variant_retail_prices.find((row) => row.sku === variant.sku)?.unit_price ??
                "",
            initial_stock: "",
        })),
        sellable_retail: pricing.sellable_retail,
        sellable_dealer: pricing.sellable_dealer,
        retail_price: pricing.retail_price ?? "",
        dealer_rules: pricing.dealer_rules,
    });
    const [errors, setErrors] = useState<WizardErrors>({});
    const [notice, setNotice] = useState("");
    const save = useMutation({
        mutationFn: () =>
            productApi.updateProductPricing(product.id, {
                sellable_retail: data.sellable_retail,
                sellable_dealer: data.sellable_dealer,
                retail_price: data.sellable_retail ? data.retail_price : null,
                variant_retail_prices: data.sellable_retail
                    ? data.variants.map((variant) => ({
                          sku: variant.sku,
                          unit_price: variant.retail_price_override || null,
                      }))
                    : [],
                dealer_rules: data.sellable_dealer ? data.dealer_rules : [],
            }),
        onSuccess: async () => {
            toast.success("Cập nhật giá sản phẩm thành công.");
            setNotice("");
            await onSaved();
        },
        onError: (reason) => {
            setNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
            toast.error(errorMessage(reason));
        },
    });
    const submit = () => {
        const found = validateStep(3, data, 0);
        if (!data.sellable_retail && !data.sellable_dealer)
            found["channels"] = "Chọn ít nhất một kênh bán.";
        setErrors(found);
        if (Object.keys(found).length) return;
        save.mutate();
    };
    return (
        <div className="space-y-5">
            <div className="rounded-xl border bg-card p-5">
                <h2 className="text-xl text-primary">Kênh bán</h2>
                <div className="mt-3 flex flex-wrap gap-5 text-sm">
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={data.sellable_retail}
                            onChange={(event) =>
                                setData({ ...data, sellable_retail: event.target.checked })
                            }
                        />
                        Retail
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={data.sellable_dealer}
                            onChange={(event) =>
                                setData({ ...data, sellable_dealer: event.target.checked })
                            }
                        />
                        Đại lý
                    </label>
                </div>
                <p className="text-sm text-red-700">{errors["channels"]}</p>
            </div>
            <PricesStep
                data={data}
                update={(patch) => setData((previous) => ({ ...previous, ...patch }))}
                errors={errors}
                tiers={tiers}
            />
            {notice && (
                <p role="status" className="text-sm text-primary">
                    {notice}
                </p>
            )}
            <div className="flex justify-end">
                <button
                    type="button"
                    disabled={save.isPending}
                    className={buttonClass}
                    onClick={submit}
                >
                    {save.isPending ? "Đang lưu..." : "Lưu giá"}
                </button>
            </div>
        </div>
    );
}

function VariantRow({
    item,
    productId,
    onSaved,
}: {
    item: ProductVariant;
    productId: number;
    onSaved: () => Promise<void>;
}) {
    const [error, setError] = useState("");
    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState({
        variant_name: item.variant_name,
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
        if (update.isPending) return false;
        setError("");
        try {
            await update.mutateAsync(body);
            toast.success(
                body["status"] === "inactive"
                    ? `Đã ngừng hoạt động SKU ${item.sku}.`
                    : body["status"] === "active"
                      ? `Đã kích hoạt SKU ${item.sku}.`
                      : "Cập nhật SKU thành công.",
            );
            return true;
        } catch (reason) {
            setError(errorMessage(reason));
            toast.error(errorMessage(reason));
            return false;
        }
    };
    return (
        <div className="rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="font-mono text-sm font-semibold">{item.sku}</p>
                    <p className="text-sm text-muted-foreground">{item.variant_name}</p>
                </div>
                <button
                    type="button"
                    className={secondaryButtonClass}
                    onClick={() => setEditing(!editing)}
                >
                    {editing ? "Đóng" : "Sửa biến thể"}
                </button>
                <label className="admin-form-field admin-form-label w-full sm:w-40">
                    Trạng thái
                    <select
                        aria-label={`Trạng thái ${item.sku}`}
                        className={fieldClass}
                        value={item.status}
                        disabled={update.isPending}
                        onChange={(event) => {
                            const status = event.target.value;
                            if (
                                status === "inactive" &&
                                !window.confirm(`Ngừng hoạt động SKU ${item.sku}?`)
                            )
                                return;
                            void change({ status });
                        }}
                    >
                        <option value="active">Hoạt động</option>
                        <option value="inactive">Ngừng hoạt động</option>
                    </select>
                </label>
            </div>
            {editing && (
                <form
                    className="mt-4 grid gap-3 sm:grid-cols-3"
                    onSubmit={async (event) => {
                        event.preventDefault();
                        if (await change(form)) {
                            setEditing(false);
                        }
                    }}
                >
                    <label className="admin-form-field admin-form-label sm:col-span-3">
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
                    <div className="flex justify-end sm:col-span-3">
                        <button className={buttonClass} disabled={update.isPending}>
                            Lưu biến thể
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
