import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import {
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "./ProductAdminShared";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { inventoryApi, inventoryKeys } from "@/services/inventoryApi";
import type { Warehouse } from "@/types/inventory";

type WarehouseForm = {
    code: string;
    name: string;
    address_line1: string;
    address_line2: string;
    city: string;
    province: string;
    country: string;
    postal_code: string;
    timezone: string;
    type: string;
    status: Warehouse["status"];
    is_default_sales: boolean;
    is_default_clinic: boolean;
};

const emptyForm: WarehouseForm = {
    code: "",
    name: "",
    address_line1: "",
    address_line2: "",
    city: "",
    province: "",
    country: "",
    postal_code: "",
    timezone: "",
    type: "",
    status: "active",
    is_default_sales: false,
    is_default_clinic: false,
};

function formFromWarehouse(item: Warehouse): WarehouseForm {
    return {
        code: item.code,
        name: item.name,
        address_line1: item.address_line1 ?? "",
        address_line2: item.address_line2 ?? "",
        city: item.city ?? "",
        province: item.province ?? "",
        country: item.country ?? "",
        postal_code: item.postal_code ?? "",
        timezone: item.timezone ?? "",
        type: item.type ?? "",
        status: item.status,
        is_default_sales: item.is_default_sales,
        is_default_clinic: item.is_default_clinic,
    };
}

export function WarehousePage() {
    const client = useQueryClient();
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("");
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<Warehouse | null>(null);
    const [form, setForm] = useState<WarehouseForm>(emptyForm);
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const filters = { search, status, page };
    const query = useQuery({
        queryKey: inventoryKeys.warehouses(filters),
        queryFn: () => inventoryApi.warehouses(filters),
    });
    const save = useMutation({
        mutationFn: () =>
            selected
                ? inventoryApi.updateWarehouse(selected.id, form)
                : inventoryApi.createWarehouse(form),
        onSuccess: async () => {
            toast.success(
                selected?.status === "active" && form.status === "inactive"
                    ? "Đã ngừng hoạt động kho hàng."
                    : selected?.status === "inactive" && form.status === "active"
                      ? "Đã kích hoạt kho hàng."
                      : selected
                        ? "Cập nhật kho hàng thành công."
                        : "Thêm kho hàng thành công.",
            );
            setNotice("");
            setErrors({});
            setSelected(null);
            setForm(emptyForm);
            await client.invalidateQueries({ queryKey: ["warehouses"] });
            await client.invalidateQueries({ queryKey: ["inventory-balances"] });
        },
        onError: (reason) => toast.error(errorMessage(reason)),
    });
    const update = <K extends keyof WarehouseForm>(key: K, value: WarehouseForm[K]) =>
        setForm((current) => ({ ...current, [key]: value }));

    return (
        <ProductAdminGuard>
            <div className="space-y-7">
                <header>
                    <p className="label-luxury">Warehouse Master</p>
                    <h1 className="mt-2 text-3xl text-primary">Kho hàng</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Mỗi số dư tồn kho thuộc một kho cụ thể. Kho ngừng hoạt động vẫn giữ lịch sử.
                    </p>
                </header>
                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]">
                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="text-xl text-primary">Danh sách kho</h2>
                        <div className="mt-4 flex flex-wrap gap-3">
                            <input
                                className={`${fieldClass} sm:max-w-sm`}
                                aria-label="Tìm kho"
                                placeholder="Mã hoặc tên kho"
                                value={search}
                                onChange={(event) => {
                                    setSearch(event.target.value);
                                    setPage(1);
                                }}
                            />
                            <select
                                className={`${fieldClass} sm:max-w-52`}
                                aria-label="Lọc trạng thái kho"
                                value={status}
                                onChange={(event) => {
                                    setStatus(event.target.value);
                                    setPage(1);
                                }}
                            >
                                <option value="">Tất cả trạng thái</option>
                                <option value="active">Đang hoạt động</option>
                                <option value="inactive">Ngừng hoạt động</option>
                            </select>
                        </div>
                        {query.isPending ? (
                            <LoadingState />
                        ) : query.isError ? (
                            <ErrorState
                                message={errorMessage(query.error)}
                                retry={() => query.refetch()}
                            />
                        ) : query.data.data.length === 0 ? (
                            <div className="mt-4">
                                <EmptyState message="Chưa có kho phù hợp." />
                            </div>
                        ) : (
                            <>
                                <div className="mt-4 divide-y">
                                    {query.data.data.map((item) => (
                                        <div
                                            key={item.id}
                                            className="flex flex-wrap items-center justify-between gap-3 py-4"
                                        >
                                            <div>
                                                <p className="font-semibold">
                                                    {item.name}{" "}
                                                    <span className="font-mono text-sm text-muted-foreground">
                                                        {item.code}
                                                    </span>
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    {item.status === "active"
                                                        ? "Đang hoạt động"
                                                        : "Ngừng hoạt động"}
                                                    {item.is_default_sales
                                                        ? " · Mặc định bán hàng"
                                                        : ""}
                                                    {item.is_default_clinic
                                                        ? " · Mặc định phòng khám"
                                                        : ""}
                                                </p>
                                            </div>
                                            <button
                                                type="button"
                                                className={secondaryButtonClass}
                                                onClick={() => {
                                                    setSelected(item);
                                                    setForm(formFromWarehouse(item));
                                                    setErrors({});
                                                    setNotice("");
                                                }}
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
                                !window.confirm(`Ngừng hoạt động kho "${selected.name}"?`)
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
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-xl text-primary">
                                {selected ? "Sửa kho" : "Thêm kho"}
                            </h2>
                            {selected && (
                                <button
                                    type="button"
                                    className="text-sm underline"
                                    onClick={() => {
                                        setSelected(null);
                                        setForm(emptyForm);
                                    }}
                                >
                                    Tạo mới
                                </button>
                            )}
                        </div>
                        {notice && (
                            <p role="status" className="text-sm text-primary">
                                {notice}
                            </p>
                        )}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(
                                [
                                    "code",
                                    "name",
                                    "address_line1",
                                    "address_line2",
                                    "city",
                                    "province",
                                    "country",
                                    "postal_code",
                                    "timezone",
                                    "type",
                                ] as const
                            ).map((key) => (
                                <label
                                    key={key}
                                    className={`admin-form-field admin-form-label ${key.startsWith("address") ? "sm:col-span-2" : ""}`}
                                >
                                    {
                                        (
                                            {
                                                code: "Mã kho",
                                                name: "Tên kho",
                                                address_line1: "Địa chỉ 1",
                                                address_line2: "Địa chỉ 2",
                                                city: "Thành phố",
                                                province: "Tỉnh",
                                                country: "Quốc gia",
                                                postal_code: "Mã bưu chính",
                                                timezone: "Múi giờ",
                                                type: "Loại kho",
                                            } as const
                                        )[key]
                                    }
                                    <input
                                        className={fieldClass}
                                        value={form[key]}
                                        required={key === "code" || key === "name"}
                                        onChange={(event) => update(key, event.target.value)}
                                    />
                                    {errors[key] && (
                                        <span className="text-red-700">{errors[key]}</span>
                                    )}
                                </label>
                            ))}
                        </div>
                        <label className="admin-form-field admin-form-label">
                            Trạng thái
                            <select
                                className={`${fieldClass} admin-field-medium`}
                                value={form.status}
                                onChange={(event) =>
                                    update("status", event.target.value as Warehouse["status"])
                                }
                            >
                                <option value="active">Đang hoạt động</option>
                                <option value="inactive">Ngừng hoạt động</option>
                            </select>
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={form.is_default_sales}
                                onChange={(event) =>
                                    update("is_default_sales", event.target.checked)
                                }
                            />{" "}
                            Kho mặc định bán hàng
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={form.is_default_clinic}
                                onChange={(event) =>
                                    update("is_default_clinic", event.target.checked)
                                }
                            />{" "}
                            Kho mặc định phòng khám
                        </label>
                        <p className="text-xs text-muted-foreground">
                            Mỗi mục đích chỉ có tối đa một kho hoạt động được đánh dấu mặc định.
                        </p>
                        <div className="flex justify-end border-t pt-4">
                            <button className={buttonClass} disabled={save.isPending}>
                                {save.isPending ? "Đang lưu..." : "Lưu kho"}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </ProductAdminGuard>
    );
}
