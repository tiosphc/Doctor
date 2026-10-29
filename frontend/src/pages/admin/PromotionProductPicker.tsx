import { useEffect, useId, useRef, useState } from "react";
import type { KeyboardEvent } from "react";
import { useQuery } from "@tanstack/react-query";
import { Package, Search } from "lucide-react";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { productApi } from "@/services/productApi";
import { formatPercentage } from "@/lib/formatPercentage";
import type { ProductFilters } from "@/services/productApi";
import type { Product } from "@/types/product";

type Props = {
    label: string;
    selectedIds: number[];
    onChoose: (product: Product) => void;
    onChooseMany?: (products: Product[]) => void;
    modalOnly?: boolean;
    triggerLabel?: string;
    giftFilter?: ProductFilters["gift_filter"];
    activeOnly?: boolean;
    multiple?: boolean;
    checkDiscountAvailability?: boolean;
    excludePromotionId?: number;
    promotionScope?: "retail" | "dealer" | "both";
    promotionTierIds?: number[];
    promotionStartsAt?: string | null;
    promotionEndsAt?: string | null;
    emptyMessage?: string;
};

function productImage(product: Product): string | null {
    return (
        product.images?.find((image) => image.is_primary)?.url ?? product.images?.[0]?.url ?? null
    );
}

function activeDiscountLabel(product: Product): string | null {
    const promotion = product.active_discount_promotion;
    if (!promotion) return null;
    return promotion.discount_type === "percentage"
        ? `Đang giảm ${formatPercentage(promotion.discount_value)}`
        : `Đang giảm ${Number(promotion.discount_value).toLocaleString("vi-VN")} đ`;
}

function ProductRow({ product, term = "" }: { product: Product; term?: string }) {
    const imageUrl = productImage(product);
    const activeVariants = product.variants.filter((variant) => variant.status === "active");
    const matchingVariants = activeVariants.filter(
        (variant) =>
            variant.sku.toLowerCase().includes(term.toLowerCase()) ||
            variant.variant_name.toLowerCase().includes(term.toLowerCase()),
    );
    const variantSummary = (matchingVariants.length ? matchingVariants : activeVariants)
        .slice(0, 2)
        .map((variant) => `${variant.variant_name} · ${variant.sku}`)
        .join(" · ");

    return (
        <span className="flex min-w-0 items-center gap-3">
            <span className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md bg-muted">
                {imageUrl ? (
                    <img src={imageUrl} alt="" className="size-full object-cover" />
                ) : (
                    <Package className="size-5 text-muted-foreground" aria-hidden="true" />
                )}
            </span>
            <span className="min-w-0 flex-1">
                <strong className="block truncate text-primary">{product.name}</strong>
                <span className="block truncate text-xs text-muted-foreground">
                    {product.product_code}
                    {variantSummary ? ` · ${variantSummary}` : ""}
                </span>
                {product.active_discount_promotion && (
                    <span className="block truncate text-xs text-amber-800">
                        {product.active_discount_promotion.name}
                    </span>
                )}
            </span>
        </span>
    );
}

export function PromotionProductPicker({
    label,
    selectedIds,
    onChoose,
    onChooseMany,
    modalOnly = false,
    triggerLabel = "+ Chọn sản phẩm",
    giftFilter,
    activeOnly = false,
    multiple = false,
    checkDiscountAvailability = false,
    excludePromotionId,
    promotionScope,
    promotionTierIds = [],
    promotionStartsAt,
    promotionEndsAt,
    emptyMessage = "Không tìm thấy sản phẩm phù hợp.",
}: Props) {
    const listboxId = useId();
    const containerRef = useRef<HTMLDivElement>(null);
    const [search, setSearch] = useState("");
    const [debouncedSearch, setDebouncedSearch] = useState("");
    const [suggestionsOpen, setSuggestionsOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);
    const [modalOpen, setModalOpen] = useState(false);
    const [modalSearch, setModalSearch] = useState("");
    const [debouncedModalSearch, setDebouncedModalSearch] = useState("");
    const [page, setPage] = useState(1);
    const [staged, setStaged] = useState<Record<number, Product>>({});

    useEffect(() => {
        const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 250);
        return () => window.clearTimeout(timer);
    }, [search]);
    useEffect(() => {
        const timer = window.setTimeout(() => setDebouncedModalSearch(modalSearch.trim()), 250);
        return () => window.clearTimeout(timer);
    }, [modalSearch]);
    useEffect(() => {
        const dismiss = (event: PointerEvent) => {
            if (!containerRef.current?.contains(event.target as Node)) setSuggestionsOpen(false);
        };
        document.addEventListener("pointerdown", dismiss);
        return () => document.removeEventListener("pointerdown", dismiss);
    }, []);

    const filters: ProductFilters = {
        ...(activeOnly ? { status: "active" } : {}),
        ...(giftFilter ? { gift_filter: giftFilter } : {}),
        ...(checkDiscountAvailability ? { discount_availability: true } : {}),
        ...(excludePromotionId ? { exclude_promotion_id: excludePromotionId } : {}),
        ...(checkDiscountAvailability ? { promotion_scope: promotionScope ?? "retail" } : {}),
        ...(promotionTierIds.length
            ? { promotion_tier_ids: [...promotionTierIds].sort((a, b) => a - b).join(",") }
            : {}),
        ...(promotionStartsAt ? { promotion_starts_at: promotionStartsAt } : {}),
        ...(promotionEndsAt ? { promotion_ends_at: promotionEndsAt } : {}),
    };
    const inline = useQuery({
        queryKey: ["promotion-product-picker", filters, debouncedSearch, 1],
        queryFn: () => productApi.adminProducts({ ...filters, search: debouncedSearch, page: 1 }),
        enabled: !modalOnly && debouncedSearch.length >= 2,
        retry: false,
    });
    const modal = useQuery({
        queryKey: ["promotion-product-picker", filters, debouncedModalSearch, page],
        queryFn: () =>
            productApi.adminProducts({
                ...filters,
                ...(debouncedModalSearch ? { search: debouncedModalSearch } : {}),
                page,
            }),
        enabled: modalOpen,
        retry: false,
    });
    const suggestions = inline.data?.data.slice(0, 8) ?? [];
    const searching = search.trim() !== debouncedSearch;
    const modalSearching = modalSearch.trim() !== debouncedModalSearch;
    const stagedProducts = Object.values(staged);

    const choose = (product: Product) => {
        if (product.active_discount_promotion) return;
        if (!selectedIds.includes(product.id)) onChoose(product);
        setSearch("");
        setSuggestionsOpen(false);
        setActiveIndex(0);
    };
    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === "Escape") {
            setSuggestionsOpen(false);
            return;
        }
        if (event.key === "Enter") event.preventDefault();
        if (
            searching ||
            inline.isPending ||
            inline.isError ||
            !suggestionsOpen ||
            suggestions.length === 0
        )
            return;
        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
            event.preventDefault();
            setActiveIndex((current) =>
                event.key === "ArrowDown"
                    ? (current + 1) % suggestions.length
                    : (current - 1 + suggestions.length) % suggestions.length,
            );
        } else if (event.key === "Enter") {
            const suggestion = suggestions[activeIndex] ?? suggestions[0];
            if (suggestion && !suggestion.active_discount_promotion) choose(suggestion);
        }
    };
    const selectModalProduct = (product: Product) => {
        if (selectedIds.includes(product.id) || product.active_discount_promotion) return;
        if (multiple) {
            setStaged((current) => {
                const next = { ...current };
                if (next[product.id]) delete next[product.id];
                else next[product.id] = product;
                return next;
            });
            return;
        }
        onChoose(product);
        setModalOpen(false);
    };

    return (
        <>
            <div ref={containerRef} className="relative min-w-0 space-y-2">
                {!modalOnly && (
                    <label className="grid gap-2 text-sm font-medium">
                        {label}
                        <span className="relative block">
                            <Search
                                className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <input
                                className="w-full rounded-md border bg-background py-2 pl-9 pr-3 font-normal"
                                value={search}
                                onChange={(event) => {
                                    setSearch(event.target.value);
                                    setSuggestionsOpen(true);
                                    setActiveIndex(0);
                                }}
                                onFocus={() => setSuggestionsOpen(true)}
                                onKeyDown={onKeyDown}
                                placeholder="Tìm tên sản phẩm, mã, SKU hoặc biến thể..."
                                autoComplete="off"
                                role="combobox"
                                aria-expanded={suggestionsOpen && search.trim().length >= 2}
                                aria-controls={listboxId}
                                aria-activedescendant={
                                    suggestionsOpen && !searching && suggestions[activeIndex]
                                        ? `${listboxId}-${suggestions[activeIndex].id}`
                                        : undefined
                                }
                            />
                        </span>
                    </label>
                )}
                {!modalOnly && suggestionsOpen && search.trim().length >= 2 && (
                    <div
                        id={listboxId}
                        role="listbox"
                        className="absolute inset-x-0 top-full z-30 max-h-80 overflow-y-auto rounded-lg border bg-background p-2 shadow-lg"
                    >
                        {searching || inline.isPending ? (
                            <p className="p-3 text-sm text-muted-foreground">
                                Đang tìm sản phẩm...
                            </p>
                        ) : inline.isError ? (
                            <div className="space-y-2 p-3 text-sm">
                                <p role="alert">Không thể tải sản phẩm.</p>
                                <button
                                    type="button"
                                    className="text-primary underline"
                                    onClick={() => void inline.refetch()}
                                >
                                    Thử lại
                                </button>
                            </div>
                        ) : suggestions.length === 0 ? (
                            <p className="p-3 text-sm text-muted-foreground">{emptyMessage}</p>
                        ) : (
                            suggestions.map((product, index) => (
                                <button
                                    key={product.id}
                                    id={`${listboxId}-${product.id}`}
                                    type="button"
                                    role="option"
                                    aria-selected={index === activeIndex}
                                    disabled={Boolean(product.active_discount_promotion)}
                                    className={`flex w-full items-center justify-between gap-2 rounded-md p-2 text-left hover:bg-accent disabled:cursor-not-allowed disabled:opacity-75 ${index === activeIndex ? "bg-accent" : ""}`}
                                    onMouseEnter={() => setActiveIndex(index)}
                                    onClick={() => choose(product)}
                                >
                                    <ProductRow product={product} term={debouncedSearch} />
                                    {activeDiscountLabel(product) ? (
                                        <span className="shrink-0 rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800">
                                            {activeDiscountLabel(product)}
                                        </span>
                                    ) : (
                                        selectedIds.includes(product.id) && (
                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                Đã chọn
                                            </span>
                                        )
                                    )}
                                </button>
                            ))
                        )}
                    </div>
                )}
                <button
                    type="button"
                    className={`rounded-md border px-3 py-2 text-sm text-primary hover:bg-accent ${modalOnly ? "w-full border-dashed py-3 text-center font-medium" : ""}`}
                    onClick={() => {
                        setSuggestionsOpen(false);
                        setModalSearch(search.trim());
                        setPage(1);
                        setModalOpen(true);
                    }}
                >
                    {triggerLabel}
                </button>
            </div>
            <Dialog
                open={modalOpen}
                onOpenChange={(open) => {
                    setModalOpen(open);
                    if (!open) setStaged({});
                }}
            >
                <DialogContent className="flex max-h-[90vh] w-[calc(100%-2rem)] max-w-5xl flex-col gap-4 overflow-hidden rounded-xl">
                    <DialogHeader>
                        <DialogTitle>{label}</DialogTitle>
                        <DialogDescription>
                            Tìm theo tên, mã, SKU hoặc biến thể rồi chọn sản phẩm phù hợp.
                        </DialogDescription>
                    </DialogHeader>
                    <label className="admin-form-field admin-form-label">
                        Tìm sản phẩm
                        <input
                            className="rounded-md border bg-background px-3 py-2"
                            value={modalSearch}
                            onChange={(event) => {
                                setModalSearch(event.target.value);
                                setPage(1);
                            }}
                            placeholder="Tìm tên sản phẩm, mã, SKU hoặc biến thể..."
                            autoFocus
                        />
                    </label>
                    <div className="min-h-0 flex-1 space-y-2 overflow-y-auto">
                        {modalSearching || modal.isPending ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Đang tải sản phẩm...
                            </p>
                        ) : modal.isError ? (
                            <div className="space-y-2 p-4 text-sm">
                                <p role="alert">Không thể tải danh sách sản phẩm.</p>
                                <button
                                    type="button"
                                    className="text-primary underline"
                                    onClick={() => void modal.refetch()}
                                >
                                    Thử lại
                                </button>
                            </div>
                        ) : modal.data.data.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">{emptyMessage}</p>
                        ) : (
                            modal.data.data.map((product) => {
                                const selected = selectedIds.includes(product.id);
                                const stagedHere = Boolean(staged[product.id]);
                                return (
                                    <button
                                        key={product.id}
                                        type="button"
                                        disabled={
                                            selected || Boolean(product.active_discount_promotion)
                                        }
                                        onClick={() => selectModalProduct(product)}
                                        className="flex w-full flex-wrap items-center justify-between gap-3 rounded-lg border p-3 text-left text-sm hover:bg-accent disabled:cursor-not-allowed disabled:opacity-75"
                                    >
                                        <ProductRow product={product} term={debouncedModalSearch} />
                                        <span className="shrink-0 rounded-md border px-3 py-2 text-primary">
                                            {activeDiscountLabel(product) ??
                                                (selected
                                                    ? "Đã chọn"
                                                    : stagedHere
                                                      ? "Bỏ chọn"
                                                      : "Chọn")}
                                        </span>
                                    </button>
                                );
                            })
                        )}
                    </div>
                    {modal.data && modal.data.last_page > 1 && (
                        <div className="flex justify-center gap-4 text-sm">
                            <button
                                type="button"
                                disabled={page <= 1}
                                onClick={() => setPage(page - 1)}
                                className="disabled:opacity-40"
                            >
                                ← Trước
                            </button>
                            <span>
                                Trang {page}/{modal.data.last_page}
                            </span>
                            <button
                                type="button"
                                disabled={page >= modal.data.last_page}
                                onClick={() => setPage(page + 1)}
                                className="disabled:opacity-40"
                            >
                                Sau →
                            </button>
                        </div>
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-4 text-sm">
                        <span>
                            Đã chọn:{" "}
                            <strong>
                                {multiple ? stagedProducts.length : selectedIds.length} sản phẩm
                            </strong>
                        </span>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                className="rounded-md border px-4 py-2"
                                onClick={() => setModalOpen(false)}
                            >
                                Đóng
                            </button>
                            {multiple && (
                                <button
                                    type="button"
                                    disabled={stagedProducts.length === 0}
                                    className="rounded-md bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50"
                                    onClick={() => {
                                        onChooseMany?.(stagedProducts);
                                        setStaged({});
                                        setModalOpen(false);
                                    }}
                                >
                                    Thêm {stagedProducts.length} sản phẩm
                                </button>
                            )}
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
