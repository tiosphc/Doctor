import { useMemo, useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ChevronDown, Ellipsis, Package, Pencil, Trash2 } from "lucide-react";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { Badge } from "@/components/common/Status";
import { Button } from "@/components/ui/button";
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { productApi, type CatalogPriceRow } from "@/services/productApi";
import { ProductAdminGuard, fieldClass } from "./ProductAdminShared";
import {
    buildDealerPriceMatrix,
    groupCatalogPriceRows,
    type CatalogProductGroup,
    type CatalogVariantGroup,
} from "./catalogPriceGroups";

type PriceContext = "retail" | "dealer";
type PriceStatus = "" | "priced" | "unpriced";

const vnd = (value: string | number) =>
    `${new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 0 }).format(Number(value))} đ`;

function priceLabel(value: string | null): string {
    return value === null ? "Chưa thiết lập giá" : vnd(value);
}

function isSelling(row: CatalogPriceRow): boolean {
    return row.status === "active" && row.product_status === "active" && row.sellable;
}

function Status({ row }: { row: CatalogPriceRow }) {
    return (
        <Badge tone={isSelling(row) ? "success" : "default"}>
            {isSelling(row) ? "Đang bán" : "Chưa mở bán"}
        </Badge>
    );
}

function Tier({ name }: { name: string | null }) {
    const tone = name?.toLowerCase().includes("gold")
        ? "warning"
        : name?.toLowerCase().includes("diamond")
          ? "info"
          : "default";
    return <Badge tone={tone}>{name ?? "Đại lý"}</Badge>;
}

function Price({ value }: { value: string | null }) {
    return (
        <span
            className={
                value === null
                    ? "text-sm text-muted-foreground"
                    : "whitespace-nowrap font-semibold tabular-nums text-primary"
            }
        >
            {priceLabel(value)}
        </span>
    );
}

function PriceActions({
    row,
    context,
    onEdit,
    onDelete,
}: {
    row: CatalogPriceRow;
    context: PriceContext;
    onEdit: (row: CatalogPriceRow) => void;
    onDelete?: (row: CatalogPriceRow) => void;
}) {
    if (row.unit_price === null) {
        return (
            <Button type="button" size="sm" variant="outline" onClick={() => onEdit(row)}>
                Thiết lập giá
            </Button>
        );
    }
    if (context === "retail") {
        return (
            <Button type="button" size="sm" variant="outline" onClick={() => onEdit(row)}>
                <Pencil aria-hidden="true" /> Sửa giá
            </Button>
        );
    }
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    size="icon"
                    variant="outline"
                    aria-label={`Thao tác giá ${row.tier_name} của SKU ${row.sku}`}
                >
                    <Ellipsis aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={() => onEdit(row)}>
                    <Pencil aria-hidden="true" /> Sửa giá
                </DropdownMenuItem>
                <DropdownMenuItem
                    className="text-red-700 focus:text-red-700"
                    onSelect={() => onDelete?.(row)}
                >
                    <Trash2 aria-hidden="true" /> Xóa giá
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function RetailVariants({
    variants,
    search,
    onEdit,
}: {
    variants: CatalogVariantGroup[];
    search: string;
    onEdit: (row: CatalogPriceRow) => void;
}) {
    return (
        <>
            <table className="hidden w-full text-left text-sm md:table">
                <thead className="border-y bg-muted/30 text-xs text-muted-foreground">
                    <tr>
                        <th className="px-5 py-3">Biến thể</th>
                        <th className="px-4 py-3">SKU</th>
                        <th className="px-4 py-3 text-right">Giá Retail</th>
                        <th className="px-4 py-3">ĐVT</th>
                        <th className="px-4 py-3">Trạng thái</th>
                        <th className="px-5 py-3 text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    {variants.map((variant) => {
                        const row = variant.prices[0]!;
                        return (
                            <tr key={variant.id} className="border-b last:border-0">
                                <td className="px-5 py-4 font-medium">
                                    {variant.name || "Mặc định"}
                                </td>
                                <td className="px-4 py-4">
                                    <Sku sku={variant.sku} search={search} />
                                </td>
                                <td className="px-4 py-4 text-right">
                                    <Price value={row.unit_price} />
                                </td>
                                <td className="px-4 py-4">{variant.unit || "—"}</td>
                                <td className="px-4 py-4">
                                    <Status row={row} />
                                </td>
                                <td className="px-5 py-4 text-right">
                                    <PriceActions row={row} context="retail" onEdit={onEdit} />
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
            <div className="divide-y md:hidden">
                {variants.map((variant) => {
                    const row = variant.prices[0]!;
                    return (
                        <div key={variant.id} className="space-y-3 p-4">
                            <div>
                                <p className="font-semibold text-primary">
                                    {variant.name || "Mặc định"}
                                </p>
                                <Sku sku={variant.sku} search={search} />
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="text-xs text-muted-foreground">Giá Retail</span>
                                <span>
                                    <Price value={row.unit_price} />
                                    {row.unit_price !== null && variant.unit && (
                                        <span className="text-xs text-muted-foreground">
                                            {" "}
                                            / {variant.unit}
                                        </span>
                                    )}
                                </span>
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <Status row={row} />
                                <PriceActions row={row} context="retail" onEdit={onEdit} />
                            </div>
                        </div>
                    );
                })}
            </div>
        </>
    );
}

function Sku({ sku, search }: { sku: string; search: string }) {
    const matches =
        Boolean(search.trim()) &&
        sku.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase());
    return (
        <span
            className={`break-all font-mono text-xs ${matches ? "rounded bg-amber-100 px-1 text-primary" : "text-muted-foreground"}`}
        >
            {sku}
        </span>
    );
}

function DealerVariants({
    variants,
    search,
    onEdit,
    onDelete,
}: {
    variants: CatalogVariantGroup[];
    search: string;
    onEdit: (row: CatalogPriceRow) => void;
    onDelete: (row: CatalogPriceRow) => void;
}) {
    const matrix = buildDealerPriceMatrix(variants);
    return (
        <>
            <div className="hidden max-w-full overflow-x-auto lg:block">
                <table
                    className="w-full table-fixed text-left text-sm"
                    style={{ minWidth: 410 + matrix.tiers.length * 170 }}
                >
                    <colgroup>
                        <col style={{ width: 170 }} />
                        <col style={{ width: 115 }} />
                        {matrix.tiers.map((tier) => (
                            <col key={tier.id} style={{ width: 170 }} />
                        ))}
                        <col style={{ width: 125 }} />
                    </colgroup>
                    <thead className="border-y bg-muted/30 text-xs text-muted-foreground">
                        <tr>
                            <th scope="col" className="px-4 py-2.5">
                                Biến thể / SKU
                            </th>
                            <th scope="col" className="px-3 py-2.5 text-right">
                                Retail
                            </th>
                            {matrix.tiers.map((tier) => (
                                <th key={tier.id} scope="col" className="px-3 py-2.5 text-right">
                                    <Tier name={tier.name} />
                                </th>
                            ))}
                            <th scope="col" className="px-3 py-2.5 text-center">
                                Trạng thái
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {matrix.rows.map(({ variant, pricesByTier }) => (
                            <tr key={variant.id} className="border-b align-middle last:border-0">
                                <th scope="row" className="px-4 py-2.5 font-normal">
                                    <span className="block font-semibold text-primary">
                                        {variant.name || "Mặc định"}
                                    </span>
                                    <Sku sku={variant.sku} search={search} />
                                </th>
                                <td className="px-3 py-2.5 text-right text-muted-foreground">
                                    {variant.retailReference === null
                                        ? "Chưa có giá"
                                        : vnd(variant.retailReference)}
                                </td>
                                {matrix.tiers.map((tier) => {
                                    const row = pricesByTier.get(tier.id);
                                    return (
                                        <td key={tier.id} className="px-3 py-2.5 text-right">
                                            {row && (
                                                <DealerTierPrice
                                                    row={row}
                                                    unit={variant.unit}
                                                    onEdit={onEdit}
                                                    onDelete={onDelete}
                                                />
                                            )}
                                        </td>
                                    );
                                })}
                                <td className="px-3 py-2.5 text-center">
                                    <Status row={variant.prices[0]!} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="divide-y lg:hidden">
                {matrix.rows.map(({ variant, pricesByTier }) => (
                    <section key={variant.id} className="space-y-3 p-4">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p className="font-semibold text-primary">
                                    {variant.name || "Mặc định"}
                                </p>
                                <Sku sku={variant.sku} search={search} />
                            </div>
                            <Status row={variant.prices[0]!} />
                        </div>
                        <div className="flex justify-between gap-3 text-sm">
                            <span className="text-muted-foreground">Retail tham khảo</span>
                            <span className="tabular-nums">
                                {variant.retailReference === null
                                    ? "Chưa có giá"
                                    : vnd(variant.retailReference)}
                            </span>
                        </div>
                        <div className="divide-y rounded-lg border">
                            {matrix.tiers.map((tier) => {
                                const row = pricesByTier.get(tier.id);
                                return row ? (
                                    <div
                                        key={tier.id}
                                        className="flex items-center justify-between gap-3 px-3 py-2"
                                    >
                                        <Tier name={tier.name} />
                                        <DealerTierPrice
                                            row={row}
                                            unit={variant.unit}
                                            onEdit={onEdit}
                                            onDelete={onDelete}
                                        />
                                    </div>
                                ) : null;
                            })}
                        </div>
                    </section>
                ))}
            </div>
        </>
    );
}

function DealerTierPrice({
    row,
    unit,
    onEdit,
    onDelete,
}: {
    row: CatalogPriceRow;
    unit: string | null;
    onEdit: (row: CatalogPriceRow) => void;
    onDelete: (row: CatalogPriceRow) => void;
}) {
    if (row.unit_price === null) {
        return (
            <div className="text-right">
                <span className="block text-xs text-muted-foreground">Chưa có giá</span>
                <button
                    type="button"
                    className="focus-premium mt-1 text-xs font-medium text-primary underline-offset-2 hover:underline"
                    aria-label={`Thiết lập giá ${row.tier_name} của SKU ${row.sku}`}
                    onClick={() => onEdit(row)}
                >
                    Thiết lập giá
                </button>
            </div>
        );
    }

    return (
        <div className="flex items-center justify-end gap-1.5">
            <div className="min-w-0 text-right">
                <Price value={row.unit_price} />
                <span className="block whitespace-nowrap text-xs text-muted-foreground">
                    MOQ {row.minimum_quantity} {unit ?? ""}
                </span>
            </div>
            <PriceActions row={row} context="dealer" onEdit={onEdit} onDelete={onDelete} />
        </div>
    );
}

function ProductGroup({
    group,
    context,
    open,
    search,
    onToggle,
    onEdit,
    onDelete,
}: {
    group: CatalogProductGroup;
    context: PriceContext;
    open: boolean;
    search: string;
    onToggle: () => void;
    onEdit: (row: CatalogPriceRow) => void;
    onDelete: (row: CatalogPriceRow) => void;
}) {
    const range =
        group.minPrice === null
            ? "Chưa có giá"
            : group.minPrice === group.maxPrice
              ? vnd(group.minPrice)
              : `${vnd(group.minPrice)} – ${vnd(group.maxPrice!)}`;
    return (
        <article className="min-w-0 overflow-hidden rounded-xl border bg-card">
            <button
                type="button"
                className="flex w-full items-center gap-3 p-4 text-left transition-colors hover:bg-muted/30 sm:gap-4"
                aria-expanded={open}
                aria-controls={`catalog-product-${group.id}`}
                onClick={onToggle}
            >
                <span className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted">
                    {group.imageUrl ? (
                        <img
                            src={group.imageUrl}
                            alt=""
                            className="size-full object-cover"
                            loading="lazy"
                        />
                    ) : (
                        <Package className="size-5 text-muted-foreground" aria-hidden="true" />
                    )}
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block truncate font-semibold text-primary">{group.name}</span>
                    <span className="block text-xs text-muted-foreground">{group.code}</span>
                    <span className="mt-1 block text-xs text-muted-foreground">
                        {group.variants.length} SKU ·{" "}
                        {context === "retail" ? range : `${group.pricedCount} mức giá đại lý`}
                        {group.missingCount > 0 && (
                            <span className="text-amber-700">
                                {" "}
                                · {group.missingCount} {context === "retail" ? "SKU" : "mức giá"}{" "}
                                chưa có giá
                            </span>
                        )}
                    </span>
                </span>
                <ChevronDown
                    className={`size-5 shrink-0 text-muted-foreground transition-transform ${open ? "rotate-180" : ""}`}
                    aria-hidden="true"
                />
            </button>
            {open && (
                <div id={`catalog-product-${group.id}`}>
                    {context === "retail" ? (
                        <RetailVariants variants={group.variants} search={search} onEdit={onEdit} />
                    ) : (
                        <DealerVariants
                            variants={group.variants}
                            search={search}
                            onEdit={onEdit}
                            onDelete={onDelete}
                        />
                    )}
                </div>
            )}
        </article>
    );
}

function PriceEditor({
    row,
    context,
    onClose,
}: {
    row: CatalogPriceRow;
    context: PriceContext;
    onClose: () => void;
}) {
    const client = useQueryClient();
    const [price, setPrice] = useState(row.unit_price ?? "");
    const [minimumQuantity, setMinimumQuantity] = useState(String(row.minimum_quantity ?? 1));
    const [error, setError] = useState("");
    const save = useMutation({
        mutationFn: () =>
            context === "retail"
                ? productApi.updateCatalogRetailPrice(row.variant_id, price)
                : productApi.updateCatalogDealerPrice(
                      row.variant_id,
                      row.tier_id!,
                      price,
                      Number(minimumQuantity),
                  ),
        onSuccess: async () => {
            toast.success("Cập nhật giá thành công.");
            await client.invalidateQueries({ queryKey: ["catalog-prices"] });
            await client.invalidateQueries({ queryKey: ["admin-product-pricing", row.product_id] });
            onClose();
        },
        onError: (reason) => {
            const message =
                firstFieldErrors(reason)["unit_price"] ||
                firstFieldErrors(reason)["minimum_quantity"] ||
                errorMessage(reason);
            setError(message);
            toast.error(message);
        },
    });
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError("");
        if (
            context === "dealer" &&
            (!Number.isInteger(Number(minimumQuantity)) || Number(minimumQuantity) < 1)
        ) {
            setError("MOQ phải là số nguyên dương.");
            return;
        }
        save.mutate();
    }
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !save.isPending) onClose();
            }}
        >
            <DialogContent className="w-[calc(100%-2rem)] max-w-lg rounded-xl">
                <DialogHeader>
                    <DialogTitle>
                        {row.unit_price === null ? "Thiết lập giá" : "Sửa giá"}
                    </DialogTitle>
                    <DialogDescription>
                        Giá hiện tại và thông tin SKU được giữ nguyên cho đến khi bạn lưu.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2 rounded-lg bg-muted/40 p-3 text-sm">
                    <p>
                        <span className="text-muted-foreground">Sản phẩm:</span> {row.product_name}
                    </p>
                    <p>
                        <span className="text-muted-foreground">Biến thể / SKU:</span>{" "}
                        {row.variant_name || "Mặc định"} · {row.sku}
                    </p>
                    {context === "dealer" && (
                        <p>
                            <span className="text-muted-foreground">Tier:</span> {row.tier_name}
                        </p>
                    )}
                    <p>
                        <span className="text-muted-foreground">Giá hiện tại:</span>{" "}
                        {priceLabel(row.unit_price)}
                    </p>
                    <p>
                        <span className="text-muted-foreground">Đơn vị:</span>{" "}
                        {row.unit_symbol || "—"}
                    </p>
                </div>
                <form onSubmit={submit} className="grid gap-4">
                    <label className="grid gap-1 text-sm font-medium">
                        Giá mới (VND)
                        <input
                            className={fieldClass}
                            aria-label="Giá mới"
                            inputMode="decimal"
                            type="number"
                            min={context === "retail" ? 0 : 0.01}
                            step="0.01"
                            required
                            value={price}
                            onChange={(event) => setPrice(event.target.value)}
                        />
                    </label>
                    {context === "dealer" && (
                        <label className="grid gap-1 text-sm font-medium">
                            MOQ ({row.unit_symbol || "đơn vị"})
                            <input
                                className={fieldClass}
                                aria-label="MOQ"
                                type="number"
                                min={1}
                                step={1}
                                required
                                value={minimumQuantity}
                                onChange={(event) => setMinimumQuantity(event.target.value)}
                            />
                        </label>
                    )}
                    {error && (
                        <p role="alert" className="text-sm text-red-700">
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={save.isPending}
                            onClick={onClose}
                        >
                            Hủy
                        </Button>
                        <Button type="submit" disabled={save.isPending}>
                            {save.isPending ? "Đang lưu..." : "Lưu giá"}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeletePriceDialog({ row, onClose }: { row: CatalogPriceRow; onClose: () => void }) {
    const client = useQueryClient();
    const remove = useMutation({
        mutationFn: () => productApi.deleteCatalogDealerPrice(row.variant_id, row.tier_id!),
        onSuccess: async () => {
            toast.success("Đã xóa giá đại lý.");
            await client.invalidateQueries({ queryKey: ["catalog-prices"] });
            await client.invalidateQueries({ queryKey: ["admin-product-pricing", row.product_id] });
            onClose();
        },
        onError: (reason) => toast.error(errorMessage(reason)),
    });
    return (
        <AlertDialog
            open
            onOpenChange={(open) => {
                if (!open && !remove.isPending) onClose();
            }}
        >
            <AlertDialogContent className="w-[calc(100%-2rem)] max-w-md rounded-xl">
                <AlertDialogHeader>
                    <AlertDialogTitle>Xóa giá đại lý?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Bạn có chắc muốn xóa giá {row.tier_name} của SKU {row.sku}? Thao tác này sẽ
                        bỏ mức giá hiện tại.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter className="gap-2">
                    <AlertDialogCancel disabled={remove.isPending}>Hủy</AlertDialogCancel>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={remove.isPending}
                        onClick={() => remove.mutate()}
                    >
                        {remove.isPending ? "Đang xóa..." : "Xóa giá"}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

export function RetailPricingPage() {
    return <CatalogPricingPage context="retail" />;
}
export function DealerPricingPage() {
    return <CatalogPricingPage context="dealer" />;
}

function CatalogPricingPage({ context }: { context: PriceContext }) {
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("");
    const [tierId, setTierId] = useState("");
    const [priceStatus, setPriceStatus] = useState<PriceStatus>("");
    const [page, setPage] = useState(1);
    const [expanded, setExpanded] = useState<Record<number, boolean>>({});
    const [editing, setEditing] = useState<CatalogPriceRow | null>(null);
    const [deleting, setDeleting] = useState<CatalogPriceRow | null>(null);
    const filters = {
        search: search.trim() || undefined,
        status: status || undefined,
        tier_id: context === "dealer" && tierId ? Number(tierId) : undefined,
        price_status: priceStatus || undefined,
        page,
    };
    const query = useQuery({
        queryKey: ["catalog-prices", context, filters],
        queryFn: () => productApi.catalogPrices(context, filters),
    });
    const tiers = useQuery({
        queryKey: ["dealer-tiers"],
        queryFn: productApi.dealerTiers,
        enabled: context === "dealer",
    });
    const groups = useMemo(() => groupCatalogPriceRows(query.data?.data ?? []), [query.data?.data]);
    const title = context === "retail" ? "Bảng giá Retail" : "Bảng giá Đại lý";
    const filtered = Boolean(search || status || tierId || priceStatus);
    return (
        <ProductAdminGuard>
            <div className="mx-auto max-w-7xl space-y-6">
                <header>
                    <p className="text-xs uppercase tracking-widest text-muted-foreground">
                        Sản phẩm
                    </p>
                    <h1 className="mt-2 text-3xl text-primary">{title}</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {context === "retail"
                            ? "Xem và quản lý giá Retail theo sản phẩm, biến thể và SKU."
                            : "So sánh giá Retail với giá đại lý theo từng SKU và Tier."}
                    </p>
                </header>
                <div
                    className={`grid gap-3 rounded-xl border bg-card p-4 ${context === "dealer" ? "md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_160px_160px_160px]" : "md:grid-cols-[minmax(0,1fr)_170px_170px]"}`}
                >
                    <input
                        aria-label="Tìm sản phẩm hoặc SKU"
                        className={fieldClass}
                        placeholder="Tên sản phẩm, mã sản phẩm hoặc SKU"
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setExpanded({});
                            setPage(1);
                        }}
                    />
                    {context === "dealer" && (
                        <select
                            aria-label="Lọc Tier"
                            className={fieldClass}
                            value={tierId}
                            onChange={(event) => {
                                setTierId(event.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">Tất cả Tier</option>
                            {tiers.data?.data
                                .filter((tier) => tier.status === "active")
                                .map((tier) => (
                                    <option key={tier.id} value={tier.id}>
                                        {tier.name}
                                    </option>
                                ))}
                        </select>
                    )}
                    <select
                        aria-label="Lọc trạng thái SKU"
                        className={fieldClass}
                        value={status}
                        onChange={(event) => {
                            setStatus(event.target.value);
                            setPage(1);
                        }}
                    >
                        <option value="">Tất cả trạng thái</option>
                        <option value="active">Đang bán</option>
                        <option value="inactive">Ngừng bán</option>
                    </select>
                    <select
                        aria-label="Lọc tình trạng giá"
                        className={fieldClass}
                        value={priceStatus}
                        onChange={(event) => {
                            setPriceStatus(event.target.value as PriceStatus);
                            setPage(1);
                        }}
                    >
                        <option value="">Mọi tình trạng giá</option>
                        <option value="priced">Đã có giá</option>
                        <option value="unpriced">Chưa có giá</option>
                    </select>
                </div>
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                ) : groups.length === 0 ? (
                    <EmptyState
                        message={
                            filtered
                                ? "Không tìm thấy bảng giá phù hợp."
                                : "Chưa có SKU nào để hiển thị."
                        }
                    />
                ) : (
                    <div className="space-y-3">
                        {groups.map((group, index) => {
                            const open =
                                expanded[group.id] ?? (Boolean(search.trim()) || index === 0);
                            return (
                                <ProductGroup
                                    key={group.id}
                                    group={group}
                                    context={context}
                                    open={open}
                                    search={search}
                                    onToggle={() =>
                                        setExpanded((current) => ({
                                            ...current,
                                            [group.id]: !open,
                                        }))
                                    }
                                    onEdit={setEditing}
                                    onDelete={setDeleting}
                                />
                            );
                        })}
                        <div className="rounded-xl border bg-card px-4 py-3">
                            <Pagination
                                current={query.data.current_page}
                                last={query.data.last_page}
                                onPage={setPage}
                            />
                            {query.data.last_page > 1 && (
                                <p className="mt-2 text-center text-xs text-muted-foreground">
                                    Phân trang theo SKU; một sản phẩm có thể tiếp tục ở trang sau.
                                </p>
                            )}
                        </div>
                    </div>
                )}
                {editing && (
                    <PriceEditor
                        key={`${editing.variant_id}-${editing.tier_id ?? "retail"}`}
                        row={editing}
                        context={context}
                        onClose={() => setEditing(null)}
                    />
                )}
                {deleting && <DeletePriceDialog row={deleting} onClose={() => setDeleting(null)} />}
            </div>
        </ProductAdminGuard>
    );
}
