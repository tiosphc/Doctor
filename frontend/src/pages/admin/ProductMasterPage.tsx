import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { productApi, productKeys, type MasterKind } from "@/services/productApi";
import type { Master } from "@/types/product";
import {
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "./ProductAdminShared";

type VisibleMasterKind = Extract<MasterKind, "categories" | "units">;

const labels: Record<VisibleMasterKind, string> = {
    categories: "Danh mục",
    units: "Đơn vị",
};
const empty = {
    code: "",
    name: "",
    description: "",
    parent_id: "",
    sort_order: "0",
    symbol: "",
    decimal_precision: "0",
    status: "active",
};

export function ProductMasterPage() {
    const client = useQueryClient();
    const [kind, setKind] = useState<VisibleMasterKind>("categories");
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<Master | null>(null);
    const [form, setForm] = useState(empty);
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const query = useQuery({
        queryKey: [...productKeys.masters(kind), page],
        queryFn: () => productApi.masters(kind, page),
    });
    const save = useMutation({
        mutationFn: () => {
            const body: Record<string, unknown> = {
                code: form.code,
                name: form.name,
                status: form.status,
            };
            if (kind !== "units") body["description"] = form.description || null;
            if (kind === "categories") {
                body["parent_id"] = form.parent_id ? Number(form.parent_id) : null;
                body["sort_order"] = Number(form.sort_order);
            }
            if (kind === "units") {
                body["symbol"] = form.symbol;
                body["decimal_precision"] = Number(form.decimal_precision);
            }
            return selected
                ? productApi.updateMaster(kind, selected.id, body)
                : productApi.createMaster(kind, body);
        },
        onSuccess: async () => {
            toast.success(
                selected?.status === "active" && form.status === "inactive"
                    ? `Đã ngừng hoạt động ${labels[kind].toLowerCase()}.`
                    : selected?.status === "inactive" && form.status === "active"
                      ? `Đã kích hoạt ${labels[kind].toLowerCase()}.`
                      : `${selected ? "Cập nhật" : "Thêm"} ${labels[kind].toLowerCase()} thành công.`,
            );
            setNotice("");
            setErrors({});
            setSelected(null);
            setForm(empty);
            await client.invalidateQueries({ queryKey: productKeys.masters(kind) });
        },
        onError: (reason) => toast.error(errorMessage(reason)),
    });
    function edit(item: Master) {
        setSelected(item);
        setErrors({});
        setForm({
            code: item.code,
            name: item.name,
            description: item.description || "",
            parent_id: item.parent_id ? String(item.parent_id) : "",
            sort_order: String(item.sort_order ?? 0),
            symbol: item.symbol || "",
            decimal_precision: String(item.decimal_precision ?? 0),
            status: item.status,
        });
    }
    return (
        <ProductAdminGuard>
            <div className="space-y-7 font-sans">
                <div>
                    <p className="label-luxury">QUẢN LÝ SẢN PHẨM</p>
                    <h1 className="mt-2 font-sans text-2xl font-semibold tracking-tight text-primary sm:text-3xl">
                        Danh mục & đơn vị
                    </h1>
                </div>
                <div className="flex flex-wrap gap-2">
                    {(Object.keys(labels) as VisibleMasterKind[]).map((value) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() => {
                                setKind(value);
                                setPage(1);
                                setSelected(null);
                                setForm(empty);
                            }}
                            className={kind === value ? buttonClass : secondaryButtonClass}
                        >
                            {labels[value]}
                        </button>
                    ))}
                </div>
                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(280px,360px)]">
                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="font-sans text-lg font-semibold text-primary">
                            {labels[kind]} hiện có
                        </h2>
                        {query.isPending ? (
                            <LoadingState />
                        ) : query.isError ? (
                            <ErrorState
                                message={errorMessage(query.error)}
                                retry={() => query.refetch()}
                            />
                        ) : query.data.data.length === 0 ? (
                            <EmptyState message="Chưa có dữ liệu." />
                        ) : (
                            <>
                                <div className="mt-4 divide-y">
                                    {query.data.data.map((item) => (
                                        <div
                                            key={item.id}
                                            className="flex items-center justify-between gap-3 py-3 text-sm"
                                        >
                                            <div>
                                                <strong>{item.name}</strong>
                                                <span className="ml-2 text-muted-foreground">
                                                    {item.code}
                                                </span>
                                                <p className="text-xs text-muted-foreground">
                                                    {item.status === "active"
                                                        ? "Đang hoạt động"
                                                        : "Ngừng hoạt động"}
                                                </p>
                                            </div>
                                            <button
                                                type="button"
                                                className="text-primary underline"
                                                onClick={() => edit(item)}
                                            >
                                                Sửa
                                            </button>
                                        </div>
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
                    <form
                        className="h-fit space-y-4 rounded-xl border bg-card p-5"
                        onSubmit={async (event) => {
                            event.preventDefault();
                            if (save.isPending) return;
                            if (
                                selected?.status === "active" &&
                                form.status === "inactive" &&
                                !window.confirm(
                                    `Ngừng hoạt động ${labels[kind].toLowerCase()} "${selected.name}"?`,
                                )
                            )
                                return;
                            setNotice("");
                            setErrors({});
                            try {
                                await save.mutateAsync();
                            } catch (reason) {
                                setNotice(errorMessage(reason));
                                setErrors(firstFieldErrors(reason));
                            }
                        }}
                    >
                        <h2 className="font-sans text-lg font-semibold text-primary">
                            {selected ? "Sửa" : "Thêm"} {labels[kind].toLowerCase()}
                        </h2>
                        {notice && (
                            <p role="status" className="text-sm text-primary">
                                {notice}
                            </p>
                        )}
                        <label className="block text-sm">
                            Mã
                            <input
                                className={fieldClass}
                                value={form.code}
                                onChange={(event) => setForm({ ...form, code: event.target.value })}
                                required
                            />
                            {errors["code"] && (
                                <span className="text-red-700">{errors["code"]}</span>
                            )}
                        </label>
                        <label className="block text-sm">
                            Tên
                            <input
                                className={fieldClass}
                                value={form.name}
                                onChange={(event) => setForm({ ...form, name: event.target.value })}
                                required
                            />
                            {errors["name"] && (
                                <span className="text-red-700">{errors["name"]}</span>
                            )}
                        </label>
                        {kind !== "units" && (
                            <label className="block text-sm">
                                Mô tả
                                <textarea
                                    className={fieldClass}
                                    value={form.description}
                                    onChange={(event) =>
                                        setForm({ ...form, description: event.target.value })
                                    }
                                />
                            </label>
                        )}
                        {kind === "categories" && (
                            <>
                                <label className="block text-sm">
                                    Danh mục cha
                                    <select
                                        className={fieldClass}
                                        value={form.parent_id}
                                        onChange={(event) =>
                                            setForm({ ...form, parent_id: event.target.value })
                                        }
                                    >
                                        <option value="">Không có</option>
                                        {query.data?.data
                                            .filter((item) => item.id !== selected?.id)
                                            .map((item) => (
                                                <option key={item.id} value={item.id}>
                                                    {item.name}
                                                </option>
                                            ))}
                                    </select>
                                    {errors["parent_id"] && (
                                        <span className="text-red-700">{errors["parent_id"]}</span>
                                    )}
                                </label>
                                <label className="block text-sm">
                                    Thứ tự
                                    <input
                                        type="number"
                                        min="0"
                                        className={fieldClass}
                                        value={form.sort_order}
                                        onChange={(event) =>
                                            setForm({ ...form, sort_order: event.target.value })
                                        }
                                    />
                                </label>
                            </>
                        )}
                        {kind === "units" && (
                            <>
                                <label className="block text-sm">
                                    Ký hiệu
                                    <input
                                        className={fieldClass}
                                        value={form.symbol}
                                        onChange={(event) =>
                                            setForm({ ...form, symbol: event.target.value })
                                        }
                                        required
                                    />
                                </label>
                                <label className="block text-sm">
                                    Số chữ số thập phân
                                    <input
                                        type="number"
                                        min="0"
                                        max="3"
                                        className={fieldClass}
                                        value={form.decimal_precision}
                                        onChange={(event) =>
                                            setForm({
                                                ...form,
                                                decimal_precision: event.target.value,
                                            })
                                        }
                                    />
                                </label>
                            </>
                        )}
                        <label className="block text-sm">
                            Trạng thái
                            <select
                                className={fieldClass}
                                value={form.status}
                                onChange={(event) =>
                                    setForm({ ...form, status: event.target.value })
                                }
                            >
                                <option value="active">Hoạt động</option>
                                <option value="inactive">Ngừng hoạt động</option>
                            </select>
                        </label>
                        <div className="flex gap-2">
                            <button disabled={save.isPending} className={buttonClass}>
                                {save.isPending ? "Đang lưu..." : "Lưu"}
                            </button>
                            {selected && (
                                <button
                                    type="button"
                                    className={secondaryButtonClass}
                                    onClick={() => {
                                        setSelected(null);
                                        setForm(empty);
                                    }}
                                >
                                    Thêm mới
                                </button>
                            )}
                        </div>
                    </form>
                </div>
            </div>
        </ProductAdminGuard>
    );
}
