import { useEffect, useRef, useState } from "react";
import type { KeyboardEvent } from "react";
import { useQuery } from "@tanstack/react-query";
import { Search } from "lucide-react";
import { toast } from "sonner";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { formatProductQuantity } from "@/lib/productQuantity";
import { errorMessage } from "@/services/api";
import { dealerApi, dealerKeys } from "./api";
import { DealerProductThumbnail } from "./DealerProductThumbnail";
import { catalogRow, matchingVariants, rowQuantityError } from "./quickOrderSelection";
import type { QuickOrderRow } from "./quickOrderSelection";
import type { DealerCatalogResponse } from "./types";

const money = (value: string) =>
    new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(Number(value));

function catalogRows(data: DealerCatalogResponse | undefined, term: string): QuickOrderRow[] {
    return (
        data?.data.flatMap((product) =>
            matchingVariants(product, term).map((variant) => catalogRow(product, variant)),
        ) ?? []
    );
}

export function QuickOrderProductPicker({
    accountId,
    userId,
    tierName,
    selectedIds,
    onAdd,
    onAddMany,
    initialSearch,
}: {
    accountId: number;
    userId: number | undefined;
    tierName: string;
    selectedIds: number[];
    onAdd: (row: QuickOrderRow) => void;
    onAddMany: (rows: QuickOrderRow[]) => number;
    initialSearch: string;
}) {
    const [search, setSearch] = useState(initialSearch);
    const [debouncedSearch, setDebouncedSearch] = useState(initialSearch);
    const [suggestionsOpen, setSuggestionsOpen] = useState(Boolean(initialSearch));
    const [activeIndex, setActiveIndex] = useState(0);
    const [modalOpen, setModalOpen] = useState(false);
    const [modalSearch, setModalSearch] = useState("");
    const [debouncedModalSearch, setDebouncedModalSearch] = useState("");
    const [page, setPage] = useState(1);
    const [staged, setStaged] = useState<Record<number, QuickOrderRow>>({});
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 250);
        return () => window.clearTimeout(timer);
    }, [search]);
    useEffect(() => {
        setSearch(initialSearch);
        setSuggestionsOpen(Boolean(initialSearch));
    }, [initialSearch]);
    useEffect(() => {
        const timer = window.setTimeout(() => setDebouncedModalSearch(modalSearch.trim()), 250);
        return () => window.clearTimeout(timer);
    }, [modalSearch]);

    const inline = useQuery({
        queryKey: dealerKeys.products(userId, accountId, debouncedSearch, 1),
        queryFn: () =>
            dealerApi.products(accountId, {
                search: debouncedSearch,
                page: 1,
            }),
        enabled: debouncedSearch.length >= 2,
        retry: false,
    });
    const modal = useQuery({
        queryKey: [...dealerKeys.products(userId, accountId, debouncedModalSearch, page), "picker"],
        queryFn: () =>
            dealerApi.products(accountId, {
                search: debouncedModalSearch,
                page,
                per_page: 15,
            }),
        enabled: modalOpen,
        retry: false,
    });
    const suggestions = catalogRows(inline.data, debouncedSearch).slice(0, 8);
    const modalItems = catalogRows(modal.data, debouncedModalSearch);
    const stagedItems = Object.values(staged);
    const isSearching = search.trim() !== debouncedSearch;

    const chooseSuggestion = (row: QuickOrderRow) => {
        onAdd(row);
        setSearch("");
        setDebouncedSearch("");
        setSuggestionsOpen(false);
        setActiveIndex(0);
        inputRef.current?.focus();
    };
    const onSearchKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === "Escape") {
            setSuggestionsOpen(false);
            return;
        }
        if (!suggestionsOpen || suggestions.length === 0) return;
        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
            event.preventDefault();
            setActiveIndex((current) =>
                event.key === "ArrowDown"
                    ? (current + 1) % suggestions.length
                    : (current - 1 + suggestions.length) % suggestions.length,
            );
        } else if (event.key === "Enter") {
            event.preventDefault();
            const suggestion = suggestions[activeIndex] ?? suggestions[0];
            if (suggestion) chooseSuggestion(suggestion);
        }
    };
    const toggleStage = (row: QuickOrderRow) => {
        setStaged((current) => {
            const next = { ...current };
            if (next[row.product_variant_id]) delete next[row.product_variant_id];
            else next[row.product_variant_id] = row;
            return next;
        });
    };
    const apply = () => {
        if (stagedItems.length === 0) return;
        if (stagedItems.some((row) => rowQuantityError(row))) {
            toast.error("Có số lượng chưa hợp lệ. Vui lòng kiểm tra các sản phẩm đã chọn.");
            return;
        }
        const added = onAddMany(stagedItems);
        if (added > 0) {
            setStaged({});
            setModalOpen(false);
        }
    };

    return (
        <>
            <section className="space-y-4 rounded-xl border bg-card p-5 sm:p-6">
                <h2 className="dealer-section-title text-primary">Thêm sản phẩm</h2>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <label className="relative grid min-w-0 flex-1 gap-2 text-sm font-medium">
                        Tìm tên sản phẩm, SKU hoặc biến thể
                        <span className="relative block">
                            <Search
                                size={18}
                                className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <input
                                ref={inputRef}
                                className="dealer-control w-full rounded-lg border bg-background pl-10 pr-3"
                                value={search}
                                onChange={(event) => {
                                    setSearch(event.target.value);
                                    setSuggestionsOpen(true);
                                    setActiveIndex(0);
                                }}
                                onFocus={() => setSuggestionsOpen(true)}
                                onKeyDown={onSearchKeyDown}
                                placeholder="Tìm tên sản phẩm, SKU hoặc biến thể..."
                                autoComplete="off"
                                role="combobox"
                                aria-expanded={
                                    suggestionsOpen && search.trim().length >= 2 && !inline.isError
                                }
                                aria-controls="quick-order-suggestions"
                                aria-activedescendant={
                                    suggestionsOpen && suggestions.length
                                        ? `quick-order-option-${suggestions[activeIndex]?.product_variant_id}`
                                        : undefined
                                }
                            />
                        </span>
                        {suggestionsOpen && search.trim().length >= 2 && (
                            <div
                                id="quick-order-suggestions"
                                role="listbox"
                                className="absolute left-0 right-0 top-full z-20 mt-1 max-h-80 overflow-y-auto rounded-lg border bg-background p-2 shadow-lg"
                            >
                                {isSearching || inline.isPending ? (
                                    <p className="p-3 text-muted-foreground">
                                        Đang tìm sản phẩm...
                                    </p>
                                ) : inline.isError ? (
                                    <div className="space-y-2 p-3">
                                        <p role="alert">
                                            Không thể tải danh sách sản phẩm. Vui lòng thử lại.
                                        </p>
                                        <button
                                            type="button"
                                            className="text-primary underline"
                                            onClick={() => void inline.refetch()}
                                        >
                                            Thử lại
                                        </button>
                                    </div>
                                ) : suggestions.length === 0 ? (
                                    <p className="p-3 text-muted-foreground">
                                        Không tìm thấy sản phẩm phù hợp. Thử tên, SKU hoặc biến thể.
                                    </p>
                                ) : (
                                    suggestions.map((row, index) => (
                                        <button
                                            key={row.product_variant_id}
                                            id={`quick-order-option-${row.product_variant_id}`}
                                            role="option"
                                            aria-selected={index === activeIndex}
                                            type="button"
                                            className={`flex min-h-16 w-full items-center gap-3 rounded-md p-2.5 text-left text-sm hover:bg-accent ${index === activeIndex ? "bg-accent" : ""}`}
                                            onMouseEnter={() => setActiveIndex(index)}
                                            onClick={() => chooseSuggestion(row)}
                                        >
                                            <DealerProductThumbnail
                                                src={row.image_url}
                                                name={row.product_name}
                                            />
                                            <span className="min-w-0 flex-1">
                                                <strong className="block truncate">
                                                    {row.product_name}
                                                </strong>
                                                <span className="block text-muted-foreground">
                                                    {row.variant_name} · SKU: {row.sku}
                                                </span>
                                                <span className="block text-primary">
                                                    {tierName}: {money(row.unit_price!)} · MOQ{" "}
                                                    {formatProductQuantity(row.minimum_quantity)}
                                                </span>
                                            </span>
                                            {selectedIds.includes(row.product_variant_id) && (
                                                <span className="dealer-meta text-muted-foreground">
                                                    Đã chọn
                                                </span>
                                            )}
                                        </button>
                                    ))
                                )}
                            </div>
                        )}
                    </label>
                    <button
                        type="button"
                        className="dealer-action rounded-lg border px-4 text-primary hover:bg-accent"
                        onClick={() => setModalOpen(true)}
                    >
                        + Chọn sản phẩm
                    </button>
                </div>
            </section>
            <Dialog
                open={modalOpen}
                onOpenChange={(open) => {
                    setModalOpen(open);
                    if (!open) setStaged({});
                }}
            >
                <DialogContent className="flex max-h-[92vh] w-[calc(100%-2rem)] max-w-[1360px] flex-col gap-4 overflow-hidden p-4 sm:w-[90vw] sm:p-6">
                    <DialogHeader>
                        <DialogTitle className="dealer-modal-title">Chọn sản phẩm</DialogTitle>
                        <DialogDescription>
                            Chọn nhiều sản phẩm và kiểm tra số lượng trước khi thêm vào đơn.
                        </DialogDescription>
                    </DialogHeader>
                    <label className="grid gap-2 text-sm font-medium">
                        Tìm tên sản phẩm, SKU hoặc biến thể
                        <input
                            autoFocus
                            className="dealer-control rounded-lg border bg-background px-3"
                            value={modalSearch}
                            onChange={(event) => {
                                setModalSearch(event.target.value);
                                setPage(1);
                            }}
                            placeholder="Tìm tên sản phẩm, SKU hoặc biến thể..."
                        />
                    </label>
                    <div className="min-h-0 flex-1 space-y-2 overflow-y-auto">
                        {modal.isPending || modalSearch.trim() !== debouncedModalSearch ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Đang tải sản phẩm...
                            </p>
                        ) : modal.isError ? (
                            <div className="space-y-2 p-4 text-sm">
                                <p role="alert">
                                    Không thể tải danh sách sản phẩm. Vui lòng thử lại.
                                </p>
                                <button
                                    type="button"
                                    className="text-primary underline"
                                    onClick={() => void modal.refetch()}
                                >
                                    Thử lại
                                </button>
                            </div>
                        ) : modalItems.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Không tìm thấy sản phẩm phù hợp.
                            </p>
                        ) : (
                            modalItems.map((row) => {
                                const stagedRow = staged[row.product_variant_id];
                                const alreadyAdded = selectedIds.includes(row.product_variant_id);
                                return (
                                    <div
                                        key={row.product_variant_id}
                                        className="grid gap-3 rounded-lg border p-4 text-sm md:grid-cols-[auto_minmax(0,1fr)_auto_auto_auto] md:items-center"
                                    >
                                        <DealerProductThumbnail
                                            src={row.image_url}
                                            name={row.product_name}
                                        />
                                        <div className="min-w-0">
                                            <strong className="block text-base text-primary">
                                                {row.product_name}
                                            </strong>
                                            <span>{row.variant_name}</span>
                                            <span className="dealer-meta block text-muted-foreground">
                                                SKU: {row.sku}
                                            </span>
                                        </div>
                                        <div className="md:text-right">
                                            <strong>{money(row.unit_price!)}</strong>
                                            <span className="dealer-meta block text-muted-foreground">
                                                {tierName} · MOQ{" "}
                                                {formatProductQuantity(row.minimum_quantity)}
                                            </span>
                                        </div>
                                        <div className="grid gap-1">
                                            <span>Số lượng</span>
                                            <span className="flex items-center">
                                                <button
                                                    type="button"
                                                    aria-label={`Giảm số lượng ${row.product_name}`}
                                                    disabled={
                                                        !stagedRow ||
                                                        !Number.isSafeInteger(
                                                            Number(stagedRow.quantity),
                                                        ) ||
                                                        Number(stagedRow.quantity) <=
                                                            Number(row.minimum_quantity)
                                                    }
                                                    className="min-h-10 rounded-l-md border px-3 disabled:opacity-40"
                                                    onClick={() =>
                                                        setStaged((current) => ({
                                                            ...current,
                                                            [row.product_variant_id]: {
                                                                ...current[row.product_variant_id]!,
                                                                quantity: String(
                                                                    Number(
                                                                        current[
                                                                            row.product_variant_id
                                                                        ]!.quantity,
                                                                    ) - 1,
                                                                ),
                                                            },
                                                        }))
                                                    }
                                                >
                                                    −
                                                </button>
                                                <input
                                                    aria-label={`Số lượng ${row.product_name}`}
                                                    type="number"
                                                    min={Number(row.minimum_quantity)}
                                                    step={1}
                                                    disabled={
                                                        !stagedRow ||
                                                        !Number.isSafeInteger(
                                                            Number(stagedRow.quantity),
                                                        )
                                                    }
                                                    className="min-h-10 w-16 border-y bg-background px-1 text-center disabled:opacity-50"
                                                    value={stagedRow?.quantity ?? row.quantity}
                                                    onChange={(event) =>
                                                        setStaged((current) => ({
                                                            ...current,
                                                            [row.product_variant_id]: {
                                                                ...(current[
                                                                    row.product_variant_id
                                                                ] ?? row),
                                                                quantity: event.target.value,
                                                            },
                                                        }))
                                                    }
                                                />
                                                <button
                                                    type="button"
                                                    aria-label={`Tăng số lượng ${row.product_name}`}
                                                    disabled={!stagedRow}
                                                    className="min-h-10 rounded-r-md border px-3 disabled:opacity-40"
                                                    onClick={() =>
                                                        setStaged((current) => ({
                                                            ...current,
                                                            [row.product_variant_id]: {
                                                                ...current[row.product_variant_id]!,
                                                                quantity: String(
                                                                    Number(
                                                                        current[
                                                                            row.product_variant_id
                                                                        ]!.quantity,
                                                                    ) + 1,
                                                                ),
                                                            },
                                                        }))
                                                    }
                                                >
                                                    +
                                                </button>
                                            </span>
                                            {stagedRow && rowQuantityError(stagedRow) && (
                                                <span
                                                    role="alert"
                                                    className="dealer-meta text-red-700"
                                                >
                                                    {rowQuantityError(stagedRow)}
                                                </span>
                                            )}
                                        </div>
                                        <button
                                            type="button"
                                            disabled={alreadyAdded}
                                            className="dealer-action rounded-md border px-3 text-primary disabled:opacity-50"
                                            onClick={() => toggleStage(row)}
                                        >
                                            {alreadyAdded
                                                ? "Đã trong đơn"
                                                : stagedRow
                                                  ? "Bỏ chọn"
                                                  : "Thêm"}
                                        </button>
                                    </div>
                                );
                            })
                        )}
                    </div>
                    {modal.data && modal.data.meta.last_page > 1 && (
                        <div className="flex justify-center gap-3 text-sm">
                            <button
                                type="button"
                                disabled={page <= 1}
                                onClick={() => setPage(page - 1)}
                                className="disabled:opacity-40"
                            >
                                ← Trước
                            </button>
                            <span>
                                Trang {page}/{modal.data.meta.last_page}
                            </span>
                            <button
                                type="button"
                                disabled={page >= modal.data.meta.last_page}
                                onClick={() => setPage(page + 1)}
                                className="disabled:opacity-40"
                            >
                                Sau →
                            </button>
                        </div>
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t bg-background pt-4 text-sm">
                        <span>
                            Đã chọn: <strong>{stagedItems.length} sản phẩm</strong>
                        </span>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                className="dealer-action rounded-md border px-4"
                                onClick={() => {
                                    setStaged({});
                                    setModalOpen(false);
                                }}
                            >
                                Hủy
                            </button>
                            <button
                                type="button"
                                disabled={
                                    stagedItems.length === 0 ||
                                    stagedItems.some((row) => Boolean(rowQuantityError(row)))
                                }
                                className="dealer-action rounded-md bg-primary px-4 text-primary-foreground disabled:opacity-50"
                                onClick={apply}
                            >
                                Thêm {stagedItems.length} sản phẩm vào đơn
                            </button>
                        </div>
                    </div>
                    {modal.isError && <span className="sr-only">{errorMessage(modal.error)}</span>}
                </DialogContent>
            </Dialog>
        </>
    );
}
