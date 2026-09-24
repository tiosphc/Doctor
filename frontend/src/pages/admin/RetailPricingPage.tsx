import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { productApi, productKeys } from "@/services/productApi";
import type { PriceList } from "@/types/product";
import {
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "./ProductAdminShared";

export function RetailPricingPage() {
    const client = useQueryClient();
    const [page, setPage] = useState(1);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [code, setCode] = useState("");
    const [name, setName] = useState("");
    const [priority, setPriority] = useState("0");
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const query = useQuery({
        queryKey: productKeys.prices(page),
        queryFn: () => productApi.priceLists(page),
    });
    const detail = useQuery({
        queryKey: ["retail-price-list", selectedId],
        queryFn: () => productApi.priceList(selectedId!),
        enabled: selectedId !== null,
    });
    const create = useMutation({
        mutationFn: () =>
            productApi.createPriceList({
                code,
                name,
                priority: Number(priority),
                pricing_context: "retail",
                scope_type: "all",
                currency: "VND",
            }),
        onSuccess: async (result) => {
            setSelectedId(result.data.id);
            setCode("");
            setName("");
            await client.invalidateQueries({ queryKey: ["retail-price-lists"] });
        },
    });
    const run = async (action: () => Promise<unknown>) => {
        setNotice("");
        setErrors({});
        try {
            await action();
            setNotice("Đã lưu bảng giá Retail.");
        } catch (reason) {
            setNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        }
    };
    return (
        <ProductAdminGuard>
            <div className="space-y-7">
                <div>
                    <p className="label-luxury">Retail Pricing</p>
                    <h1 className="mt-2 text-3xl text-primary">Bảng giá Retail</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Giá Retail áp dụng cho khách mua lẻ. Đây là dữ liệu riêng, không phải giá
                        Silver Dealer.
                    </p>
                </div>
                {notice && (
                    <p role="status" className="rounded-md border p-3 text-sm">
                        {notice}
                    </p>
                )}
                <form
                    className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void run(() => create.mutateAsync());
                    }}
                >
                    <h2 className="sm:col-span-4 text-xl text-primary">Thêm bảng giá</h2>
                    <label className="text-sm">
                        Mã
                        <input
                            required
                            className={fieldClass}
                            value={code}
                            onChange={(event) => setCode(event.target.value)}
                        />
                        {errors["code"] && <span className="text-red-700">{errors["code"]}</span>}
                    </label>
                    <label className="text-sm sm:col-span-2">
                        Tên
                        <input
                            required
                            className={fieldClass}
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </label>
                    <label className="text-sm">
                        Ưu tiên
                        <input
                            type="number"
                            min="0"
                            className={fieldClass}
                            value={priority}
                            onChange={(event) => setPriority(event.target.value)}
                        />
                    </label>
                    <div className="sm:col-span-4">
                        <button disabled={create.isPending} className={buttonClass}>
                            Tạo bảng giá Retail
                        </button>
                    </div>
                </form>
                <div className="grid gap-6 xl:grid-cols-[minmax(280px,360px)_minmax(0,1fr)]">
                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="text-xl text-primary">Bảng giá</h2>
                        {query.isPending ? (
                            <LoadingState />
                        ) : query.isError ? (
                            <ErrorState
                                message={errorMessage(query.error)}
                                retry={() => query.refetch()}
                            />
                        ) : query.data.data.length === 0 ? (
                            <EmptyState message="Chưa có bảng giá." />
                        ) : (
                            <>
                                <div className="mt-3 divide-y">
                                    {query.data.data.map((list) => (
                                        <button
                                            key={list.id}
                                            type="button"
                                            onClick={() => setSelectedId(list.id)}
                                            className={`w-full py-3 text-left text-sm hover:text-primary ${selectedId === list.id ? "font-semibold text-primary" : ""}`}
                                        >
                                            <span className="font-mono">{list.code}</span> ·{" "}
                                            {list.name}
                                            <span className="block text-xs text-muted-foreground">
                                                {list.status} · {list.items_count || 0} giá
                                            </span>
                                        </button>
                                    ))}
                                </div>
                                <Pagination
                                    current={query.data.current_page}
                                    last={query.data.last_page}
                                    onPage={setPage}
                                />
                            </>
                        )}
                    </section>
                    <section>
                        {selectedId === null ? (
                            <EmptyState message="Chọn bảng giá để quản lý giá theo SKU." />
                        ) : detail.isPending ? (
                            <LoadingState />
                        ) : detail.isError ? (
                            <ErrorState
                                message={errorMessage(detail.error)}
                                retry={() => detail.refetch()}
                            />
                        ) : (
                            <PriceListEditor
                                key={selectedId}
                                list={detail.data.data}
                                onSaved={async () => {
                                    await client.invalidateQueries({
                                        queryKey: ["retail-price-list", selectedId],
                                    });
                                    await client.invalidateQueries({
                                        queryKey: ["retail-price-lists"],
                                    });
                                }}
                            />
                        )}
                    </section>
                </div>
            </div>
        </ProductAdminGuard>
    );
}

function PriceListEditor({ list, onSaved }: { list: PriceList; onSaved: () => Promise<void> }) {
    const [status, setStatus] = useState(list.status);
    const [from, setFrom] = useState(list.effective_from?.slice(0, 16) || "");
    const [to, setTo] = useState(list.effective_to?.slice(0, 16) || "");
    const [productSearch, setProductSearch] = useState("");
    const [productId, setProductId] = useState("");
    const [variantId, setVariantId] = useState("");
    const [unitPrice, setUnitPrice] = useState("");
    const [itemFrom, setItemFrom] = useState("");
    const [itemTo, setItemTo] = useState("");
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const products = useQuery({
        queryKey: ["price-products", productSearch],
        queryFn: () => productApi.adminProducts({ search: productSearch, page: 1 }),
    });
    const selectedProduct = products.data?.data.find((item) => item.id === Number(productId));
    const update = useMutation({
        mutationFn: () =>
            productApi.updatePriceList(list.id, {
                status,
                effective_from: from || null,
                effective_to: to || null,
            }),
        onSuccess: onSaved,
    });
    const add = useMutation({
        mutationFn: () =>
            productApi.createPriceItem(list.id, {
                product_variant_id: Number(variantId),
                unit_price: unitPrice,
                effective_from: itemFrom || null,
                effective_to: itemTo || null,
            }),
        onSuccess: onSaved,
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
        <div className="space-y-5">
            <form
                className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    void run(() => update.mutateAsync());
                }}
            >
                <h2 className="sm:col-span-2 text-xl text-primary">
                    {list.name} <span className="font-mono text-sm">{list.code}</span>
                </h2>
                <p className="sm:col-span-2 text-sm text-muted-foreground">
                    Retail · VND · Tất cả kho · Ưu tiên {list.priority}
                </p>
                <label className="text-sm">
                    Hiệu lực từ
                    <input
                        type="datetime-local"
                        className={fieldClass}
                        value={from}
                        onChange={(event) => setFrom(event.target.value)}
                    />
                </label>
                <label className="text-sm">
                    Hiệu lực đến
                    <input
                        type="datetime-local"
                        className={fieldClass}
                        value={to}
                        onChange={(event) => setTo(event.target.value)}
                    />
                </label>
                <label className="text-sm">
                    Trạng thái
                    <select
                        className={fieldClass}
                        value={status}
                        onChange={(event) => setStatus(event.target.value as PriceList["status"])}
                    >
                        <option value="active">Hoạt động</option>
                        <option value="inactive">Ngừng hoạt động</option>
                    </select>
                </label>
                <div className="sm:col-span-2">
                    <button disabled={update.isPending} className={buttonClass}>
                        Lưu bảng giá
                    </button>
                </div>
            </form>
            <div className="rounded-xl border bg-card p-5">
                <h3 className="text-lg text-primary">Giá theo SKU</h3>
                {list.items?.length ? (
                    <div className="mt-3 divide-y">
                        {list.items.map((item) => (
                            <PriceItemRow
                                key={item.id}
                                item={item}
                                listId={list.id}
                                onSaved={onSaved}
                            />
                        ))}
                    </div>
                ) : (
                    <EmptyState message="Chưa có giá cho SKU nào." />
                )}
            </div>
            <form
                className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    void run(async () => {
                        await add.mutateAsync();
                        setUnitPrice("");
                        setVariantId("");
                    });
                }}
            >
                <h3 className="sm:col-span-2 text-lg text-primary">Thêm giá Retail</h3>
                {notice && (
                    <p role="status" className="sm:col-span-2 text-sm">
                        {notice}
                    </p>
                )}
                <label className="sm:col-span-2 text-sm">
                    Tìm sản phẩm
                    <input
                        className={fieldClass}
                        value={productSearch}
                        onChange={(event) => {
                            setProductSearch(event.target.value);
                            setProductId("");
                            setVariantId("");
                        }}
                        placeholder="Tên, mã hoặc SKU"
                    />
                </label>
                <label className="text-sm">
                    Sản phẩm
                    <select
                        className={fieldClass}
                        value={productId}
                        onChange={(event) => {
                            setProductId(event.target.value);
                            setVariantId("");
                        }}
                    >
                        <option value="">Chọn sản phẩm</option>
                        {products.data?.data.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.product_code} · {item.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-sm">
                    SKU
                    <select
                        required
                        className={fieldClass}
                        value={variantId}
                        onChange={(event) => setVariantId(event.target.value)}
                    >
                        <option value="">Chọn SKU</option>
                        {selectedProduct?.variants.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.sku} · {item.variant_name}
                            </option>
                        ))}
                    </select>
                    {errors["product_variant_id"] && (
                        <span className="text-red-700">{errors["product_variant_id"]}</span>
                    )}
                </label>
                <label className="text-sm">
                    Đơn giá VND
                    <input
                        required
                        type="number"
                        min="0"
                        step="0.01"
                        className={fieldClass}
                        value={unitPrice}
                        onChange={(event) => setUnitPrice(event.target.value)}
                    />
                    {errors["unit_price"] && (
                        <span className="text-red-700">{errors["unit_price"]}</span>
                    )}
                </label>
                <label className="text-sm">
                    Từ ngày
                    <input
                        type="datetime-local"
                        className={fieldClass}
                        value={itemFrom}
                        onChange={(event) => setItemFrom(event.target.value)}
                    />
                </label>
                <label className="text-sm">
                    Đến ngày
                    <input
                        type="datetime-local"
                        className={fieldClass}
                        value={itemTo}
                        onChange={(event) => setItemTo(event.target.value)}
                    />
                </label>
                <div className="sm:col-span-2">
                    <button disabled={add.isPending} className={buttonClass}>
                        Thêm giá
                    </button>
                </div>
            </form>
        </div>
    );
}

function PriceItemRow({
    item,
    listId,
    onSaved,
}: {
    item: NonNullable<PriceList["items"]>[number];
    listId: number;
    onSaved: () => Promise<void>;
}) {
    const [editing, setEditing] = useState(false);
    const [price, setPrice] = useState(item.unit_price);
    const [status, setStatus] = useState(item.status);
    const [error, setError] = useState("");
    const update = useMutation({
        mutationFn: () =>
            productApi.updatePriceItem(listId, item.id, { unit_price: price, status }),
        onSuccess: onSaved,
    });
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
            <div>
                <p className="font-mono">
                    {item.variant?.sku || `SKU #${item.product_variant_id}`}
                </p>
                <p className="font-semibold">
                    {new Intl.NumberFormat("vi-VN").format(Number(item.unit_price))} ₫
                </p>
                <p className="text-xs text-muted-foreground">
                    {item.status === "active" ? "Đang áp dụng" : "Ngừng áp dụng"}
                </p>
            </div>
            {editing ? (
                <form
                    className="flex gap-2"
                    onSubmit={async (event) => {
                        event.preventDefault();
                        setError("");
                        try {
                            await update.mutateAsync();
                            setEditing(false);
                        } catch (reason) {
                            setError(errorMessage(reason));
                        }
                    }}
                >
                    <input
                        type="number"
                        min="0"
                        step="0.01"
                        aria-label="Giá Retail"
                        className={fieldClass}
                        value={price}
                        onChange={(event) => setPrice(event.target.value)}
                    />
                    <select
                        aria-label="Trạng thái giá"
                        className={fieldClass}
                        value={status}
                        onChange={(event) => setStatus(event.target.value as "active" | "inactive")}
                    >
                        <option value="active">Hoạt động</option>
                        <option value="inactive">Ngừng áp dụng</option>
                    </select>
                    <button className={buttonClass}>Lưu</button>
                </form>
            ) : (
                <button
                    className={secondaryButtonClass}
                    type="button"
                    onClick={() => setEditing(true)}
                >
                    Sửa giá
                </button>
            )}
            {error && (
                <p role="alert" className="w-full text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}
