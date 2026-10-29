import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { procurementApi } from "@/services/procurementApi";
import type { Supplier } from "@/types/procurement";
import {
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "./ProductAdminShared";

function ErrorText({ error }: { error: unknown }) {
    return error ? (
        <p role="alert" className="text-sm text-red-700">
            {errorMessage(error)}
        </p>
    ) : null;
}

export function SupplierPage() {
    const client = useQueryClient();
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<Supplier | null>(null);
    const [form, setForm] = useState({
        code: "",
        name: "",
        contact_name: "",
        email: "",
        phone: "",
        tax_code: "",
        address: "",
        status: "active" as Supplier["status"],
    });
    const list = useQuery({
        queryKey: ["suppliers", search, page],
        queryFn: () => procurementApi.suppliers({ search, page }),
    });
    const save = useMutation({
        mutationFn: () =>
            selected
                ? procurementApi.updateSupplier(selected.id, form)
                : procurementApi.createSupplier(form),
        onSuccess: async () => {
            toast.success(
                selected?.status === "active" && form.status === "inactive"
                    ? "Đã ngừng hoạt động nhà cung cấp."
                    : selected?.status === "inactive" && form.status === "active"
                      ? "Đã kích hoạt nhà cung cấp."
                      : selected
                        ? "Cập nhật nhà cung cấp thành công."
                        : "Thêm nhà cung cấp thành công.",
            );
            setSelected(null);
            setForm({
                code: "",
                name: "",
                contact_name: "",
                email: "",
                phone: "",
                tax_code: "",
                address: "",
                status: "active",
            });
            await client.invalidateQueries({ queryKey: ["suppliers"] });
        },
        onError: (reason) => toast.error(errorMessage(reason)),
    });
    const errors = firstFieldErrors(save.error);
    const select = (supplier: Supplier) => {
        setSelected(supplier);
        setForm({
            code: supplier.code,
            name: supplier.name,
            contact_name: supplier.contact_name ?? "",
            email: supplier.email ?? "",
            phone: supplier.phone ?? "",
            tax_code: supplier.tax_code ?? "",
            address: supplier.address ?? "",
            status: supplier.status,
        });
    };

    return (
        <ProductAdminGuard>
            <div className="space-y-6">
                <header>
                    <p className="label-luxury">Procurement</p>
                    <h1 className="mt-2 text-3xl text-primary">Nhà cung cấp</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Quản lý đối tác cung ứng và trạng thái sử dụng.
                    </p>
                </header>
                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(300px,400px)]">
                    <section className="rounded-xl border bg-card p-5">
                        <input
                            className={`${fieldClass} max-w-md`}
                            aria-label="Tìm nhà cung cấp"
                            placeholder="Mã hoặc tên nhà cung cấp"
                            value={search}
                            onChange={(event) => {
                                setSearch(event.target.value);
                                setPage(1);
                            }}
                        />
                        {list.isLoading ? (
                            <LoadingState />
                        ) : list.isError ? (
                            <ErrorState
                                message={errorMessage(list.error)}
                                retry={() => void list.refetch()}
                            />
                        ) : list.data?.data.length ? (
                            <>
                                <div className="mt-4 space-y-2">
                                    {list.data.data.map((supplier) => (
                                        <button
                                            key={supplier.id}
                                            type="button"
                                            onClick={() => select(supplier)}
                                            className="flex w-full items-center justify-between gap-3 rounded-lg border px-4 py-3 text-left hover:bg-muted"
                                        >
                                            <span>
                                                <strong>{supplier.name}</strong>
                                                <span className="block text-xs text-muted-foreground">
                                                    {supplier.code} ·{" "}
                                                    {supplier.phone || "Chưa có số điện thoại"}
                                                </span>
                                            </span>
                                            <span className="text-xs">
                                                {supplier.status === "active"
                                                    ? "Hoạt động"
                                                    : "Ngừng"}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                                <Pagination
                                    current={list.data.current_page}
                                    last={list.data.last_page}
                                    onPage={setPage}
                                />
                            </>
                        ) : (
                            <div className="mt-4">
                                <EmptyState message="Chưa có nhà cung cấp." />
                            </div>
                        )}
                    </section>
                    <form
                        className="space-y-4 rounded-xl border bg-card p-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (save.isPending) return;
                            if (
                                selected?.status === "active" &&
                                form.status === "inactive" &&
                                !window.confirm(`Ngừng hoạt động nhà cung cấp "${selected.name}"?`)
                            )
                                return;
                            save.mutate();
                        }}
                    >
                        <h2 className="text-xl text-primary">
                            {selected ? "Sửa nhà cung cấp" : "Thêm nhà cung cấp"}
                        </h2>
                        {(
                            [
                                ["code", "Mã *"],
                                ["name", "Tên *"],
                                ["contact_name", "Người liên hệ"],
                                ["email", "Email"],
                                ["phone", "Điện thoại"],
                                ["tax_code", "Mã số thuế"],
                                ["address", "Địa chỉ"],
                            ] as const
                        ).map(([key, label]) => (
                            <label key={key} className="admin-form-field admin-form-label">
                                {label}
                                <input
                                    className={fieldClass}
                                    value={form[key]}
                                    onChange={(event) =>
                                        setForm((current) => ({
                                            ...current,
                                            [key]: event.target.value,
                                        }))
                                    }
                                    required={key === "code" || key === "name"}
                                />
                                {errors[key] && (
                                    <span className="text-xs text-red-700">{errors[key]}</span>
                                )}
                            </label>
                        ))}
                        <label className="admin-form-field admin-form-label">
                            Trạng thái
                            <select
                                className={`${fieldClass} admin-field-medium`}
                                value={form.status}
                                onChange={(event) =>
                                    setForm((current) => ({
                                        ...current,
                                        status: event.target.value as Supplier["status"],
                                    }))
                                }
                            >
                                <option value="active">Hoạt động</option>
                                <option value="inactive">Ngừng</option>
                            </select>
                        </label>
                        <ErrorText error={save.error} />
                        <div className="flex flex-wrap justify-end gap-2 border-t pt-4">
                            {selected && (
                                <button
                                    type="button"
                                    className={secondaryButtonClass}
                                    onClick={() => {
                                        setSelected(null);
                                        setForm({
                                            code: "",
                                            name: "",
                                            contact_name: "",
                                            email: "",
                                            phone: "",
                                            tax_code: "",
                                            address: "",
                                            status: "active",
                                        });
                                    }}
                                >
                                    Tạo mới
                                </button>
                            )}
                            <button className={buttonClass} disabled={save.isPending}>
                                {save.isPending ? "Đang lưu..." : "Lưu"}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </ProductAdminGuard>
    );
}
