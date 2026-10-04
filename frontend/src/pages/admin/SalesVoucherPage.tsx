import { useEffect, useRef, useState, type FormEvent } from "react";
import { useNavigate } from "@tanstack/react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { adminFormLayout } from "@/components/admin/AdminFormLayout";
import { formatPercentage } from "@/lib/formatPercentage";
import { apiRequest, errorMessage, firstFieldErrors } from "@/services/api";
import type { RawPage } from "@/types/product";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { PromotionDateRangePicker } from "./PromotionDateRangePicker";
import { fieldClass } from "./ProductAdminShared";

type SalesVoucher = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    discount_type: "percentage" | "fixed_amount";
    discount_value: string;
    max_discount_amount: string | null;
    minimum_order_amount: string;
    starts_at: string | null;
    ends_at: string | null;
    total_usage_limit: number | null;
    per_buyer_usage_limit: number | null;
    status: "active" | "inactive";
    redeemed_count: number;
};

const empty = {
    code: "",
    name: "",
    description: "",
    discount_type: "percentage" as "percentage" | "fixed_amount",
    discount_value: "",
    max_discount_amount: "",
    minimum_order_amount: "0",
    starts_at: "",
    ends_at: "",
    total_usage_limit: "",
    per_buyer_usage_limit: "",
    status: "active" as "active" | "inactive",
};

export function SalesVoucherPage({
    mode = "list",
    voucherId,
}: {
    mode?: "list" | "create" | "edit";
    voucherId?: number;
}) {
    const navigate = useNavigate();
    const client = useQueryClient();
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [statusFilter, setStatusFilter] = useState("");
    const editingId = mode === "edit" ? (voucherId ?? null) : null;
    const loadedEditId = useRef<number | null>(null);
    const [editingStatus, setEditingStatus] = useState<SalesVoucher["status"] | null>(null);
    const [form, setForm] = useState({ ...empty });
    const [notice, setNotice] = useState("");
    const list = useQuery({
        queryKey: ["sales-vouchers", page, search, statusFilter],
        queryFn: () =>
            apiRequest<RawPage<SalesVoucher>>("/api/admin/sales-vouchers", {
                query: { page, search, status: statusFilter },
            }),
        enabled: mode === "list",
    });
    const editDetail = useQuery({
        queryKey: ["sales-voucher-edit", editingId],
        queryFn: () => apiRequest<{ data: SalesVoucher }>(`/api/admin/sales-vouchers/${editingId}`),
        enabled: editingId !== null,
    });
    const generateCode = useMutation({
        mutationFn: () => apiRequest<{ code: string }>("/api/admin/sales-vouchers/generate-code"),
        onSuccess: ({ code }) => set("code", code),
        onError: (error) => toast.error(errorMessage(error)),
    });
    const save = useMutation({
        mutationFn: () =>
            apiRequest(`/api/admin/sales-vouchers${editingId === null ? "" : `/${editingId}`}`, {
                method: editingId === null ? "POST" : "PUT",
                body: {
                    ...form,
                    description: form.description.trim() || null,
                    max_discount_amount:
                        form.discount_type === "percentage"
                            ? form.max_discount_amount || null
                            : null,
                    minimum_order_amount: form.minimum_order_amount || "0",
                    starts_at: form.starts_at || null,
                    ends_at: form.ends_at || null,
                    total_usage_limit: form.total_usage_limit || null,
                    per_buyer_usage_limit: form.per_buyer_usage_limit || null,
                },
            }),
        onSuccess: async () => {
            toast.success(
                editingId === null
                    ? "Tạo voucher đơn hàng thành công."
                    : editingStatus === "active" && form.status === "inactive"
                      ? "Đã ngừng voucher đơn hàng."
                      : editingStatus === "inactive" && form.status === "active"
                        ? "Đã kích hoạt voucher đơn hàng."
                        : "Cập nhật voucher đơn hàng thành công.",
            );
            setNotice("");
            await client.invalidateQueries({ queryKey: ["sales-vouchers"] });
            void navigate({ to: "/admin/sales-vouchers" });
        },
        onError: (error) => {
            setNotice(errorMessage(error));
            toast.error(errorMessage(error));
        },
    });
    const fieldErrors = firstFieldErrors(save.error);
    const set = (key: keyof typeof empty, value: string) => {
        save.reset();
        setForm((current) => ({ ...current, [key]: value }));
    };
    const populateForm = (voucher: SalesVoucher) => {
        setEditingStatus(voucher.status);
        setForm({
            code: voucher.code,
            name: voucher.name,
            description: voucher.description ?? "",
            discount_type: voucher.discount_type,
            discount_value: voucher.discount_value,
            max_discount_amount: voucher.max_discount_amount ?? "",
            minimum_order_amount: voucher.minimum_order_amount,
            starts_at: voucher.starts_at?.slice(0, 16) ?? "",
            ends_at: voucher.ends_at?.slice(0, 16) ?? "",
            total_usage_limit: voucher.total_usage_limit?.toString() ?? "",
            per_buyer_usage_limit: voucher.per_buyer_usage_limit?.toString() ?? "",
            status: voucher.status,
        });
    };
    useEffect(() => {
        if (editingId !== null && editDetail.data?.data && loadedEditId.current !== editingId) {
            loadedEditId.current = editingId;
            populateForm(editDetail.data.data);
        }
    }, [editingId, editDetail.data]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (save.isPending) return;
        if (
            editingStatus === "active" &&
            form.status === "inactive" &&
            !window.confirm("Ngừng sử dụng voucher đơn hàng này?")
        )
            return;
        setNotice("");
        save.mutate();
    };
    const fieldError = (field: string) =>
        fieldErrors[field] ? (
            <span className="text-xs text-red-700">{fieldErrors[field]}</span>
        ) : null;

    return (
        <main className={`${mode === "list" ? "w-full" : adminFormLayout.standard} space-y-6`}>
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p className="label-luxury">Bán hàng Retail</p>
                    <h1 className="admin-page-title mt-2 text-primary">
                        {mode === "list"
                            ? "Voucher bán lẻ"
                            : mode === "create"
                              ? "Thêm voucher"
                              : "Sửa voucher"}
                    </h1>
                    <p className="admin-helper-text mt-2">
                        Quản lý mã giảm giá cho đơn hàng Retail.
                    </p>
                </div>
                {mode === "list" ? (
                    <button
                        type="button"
                        className="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground"
                        onClick={() => void navigate({ to: "/admin/sales-vouchers/create" })}
                    >
                        + Thêm voucher
                    </button>
                ) : (
                    <button
                        type="button"
                        className="rounded-md border px-4 py-2 text-sm"
                        onClick={() => void navigate({ to: "/admin/sales-vouchers" })}
                    >
                        ← Quay lại danh sách
                    </button>
                )}
            </header>
            {notice && (
                <p role="status" className="rounded-md border bg-card p-3 text-sm">
                    {notice}
                </p>
            )}
            {mode !== "list" &&
                (editDetail.isPending && mode === "edit" ? (
                    <LoadingState />
                ) : editDetail.isError && mode === "edit" ? (
                    <ErrorState
                        message={errorMessage(editDetail.error)}
                        retry={() => void editDetail.refetch()}
                    />
                ) : (
                    <form onSubmit={submit} className="space-y-5">
                        <section className="space-y-4 rounded-xl border bg-card p-5 shadow-sm">
                            <h2 className="admin-section-title text-primary">Thông tin cơ bản</h2>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="admin-form-label grid gap-1.5">
                                    <label htmlFor="sales-voucher-code">Mã voucher</label>
                                    <div className="flex gap-2">
                                        <input
                                            id="sales-voucher-code"
                                            className={`${fieldClass} min-w-0 flex-1`}
                                            value={form.code}
                                            required
                                            maxLength={80}
                                            onChange={(e) => set("code", e.target.value)}
                                        />
                                        {editingId === null && (
                                            <button
                                                type="button"
                                                className="shrink-0 rounded-md border px-3 py-2 text-sm"
                                                disabled={generateCode.isPending || save.isPending}
                                                onClick={() => generateCode.mutate()}
                                            >
                                                {generateCode.isPending ? "Đang tạo..." : "Tạo mã"}
                                            </button>
                                        )}
                                    </div>
                                    {fieldError("code")}
                                </div>
                                <label className="admin-form-label grid gap-1.5">
                                    Tên voucher
                                    <input
                                        className={fieldClass}
                                        value={form.name}
                                        required
                                        onChange={(e) => set("name", e.target.value)}
                                    />
                                    {fieldError("name")}
                                </label>
                            </div>
                        </section>
                        <section className="space-y-4 rounded-xl border bg-card p-5 shadow-sm">
                            <h2 className="admin-section-title text-primary">Giá trị & giới hạn</h2>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <label className="admin-form-label grid gap-1.5">
                                    Kiểu giảm
                                    <select
                                        className={fieldClass}
                                        value={form.discount_type}
                                        onChange={(e) => set("discount_type", e.target.value)}
                                    >
                                        <option value="percentage">Phần trăm</option>
                                        <option value="fixed_amount">Số tiền cố định</option>
                                    </select>
                                    {fieldError("discount_type")}
                                </label>
                                <label className="admin-form-label grid gap-1.5">
                                    Giá trị giảm
                                    <input
                                        className={`${fieldClass} max-w-56`}
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        value={form.discount_value}
                                        required
                                        onChange={(e) => set("discount_value", e.target.value)}
                                    />
                                    {fieldError("discount_value")}
                                </label>
                                <label className="admin-form-label grid gap-1.5">
                                    Đơn tối thiểu
                                    <input
                                        className={`${fieldClass} max-w-56`}
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={form.minimum_order_amount}
                                        onChange={(e) =>
                                            set("minimum_order_amount", e.target.value)
                                        }
                                    />
                                    {fieldError("minimum_order_amount")}
                                </label>
                                {form.discount_type === "percentage" && (
                                    <label className="admin-form-label grid gap-1.5">
                                        Giảm tối đa
                                        <input
                                            className={`${fieldClass} max-w-56`}
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            value={form.max_discount_amount}
                                            onChange={(e) =>
                                                set("max_discount_amount", e.target.value)
                                            }
                                        />
                                        {fieldError("max_discount_amount")}
                                    </label>
                                )}
                                <label className="admin-form-label grid gap-1.5">
                                    Tổng lượt dùng
                                    <input
                                        className={`${fieldClass} max-w-48`}
                                        type="number"
                                        min="1"
                                        step="1"
                                        value={form.total_usage_limit}
                                        onChange={(e) => set("total_usage_limit", e.target.value)}
                                    />
                                    {fieldError("total_usage_limit")}
                                </label>
                                <label className="admin-form-label grid gap-1.5">
                                    Lượt mỗi khách
                                    <input
                                        className={`${fieldClass} max-w-48`}
                                        type="number"
                                        min="1"
                                        step="1"
                                        value={form.per_buyer_usage_limit}
                                        onChange={(e) =>
                                            set("per_buyer_usage_limit", e.target.value)
                                        }
                                    />
                                    {fieldError("per_buyer_usage_limit")}
                                </label>
                                <label className="admin-form-label grid gap-1.5">
                                    Trạng thái
                                    <select
                                        className={fieldClass}
                                        value={form.status}
                                        onChange={(e) => set("status", e.target.value)}
                                    >
                                        <option value="active">Đang hoạt động</option>
                                        <option value="inactive">Tạm ngừng</option>
                                    </select>
                                </label>
                            </div>
                        </section>
                        <section className="space-y-3 rounded-xl border bg-card p-5 shadow-sm">
                            <h2 className="admin-section-title text-primary">Thời gian áp dụng</h2>
                            <PromotionDateRangePicker
                                start={form.starts_at || null}
                                end={form.ends_at || null}
                                onChange={(startsAt, endsAt) => {
                                    save.reset();
                                    setForm((current) => ({
                                        ...current,
                                        starts_at: startsAt ?? "",
                                        ends_at: endsAt ?? "",
                                    }));
                                }}
                                errors={[fieldErrors["starts_at"], fieldErrors["ends_at"]].filter(
                                    (error): error is string => Boolean(error),
                                )}
                            />
                        </section>
                        <section className="space-y-3 rounded-xl border bg-card p-5 shadow-sm">
                            <h2 className="admin-section-title text-primary">Mô tả</h2>
                            <label className="admin-form-label grid gap-1.5">
                                Nội dung
                                <textarea
                                    className={fieldClass}
                                    rows={2}
                                    value={form.description}
                                    onChange={(e) => set("description", e.target.value)}
                                />
                            </label>
                        </section>
                        <div className="flex flex-col justify-end gap-2 rounded-xl border bg-card p-4 sm:flex-row">
                            <button
                                type="button"
                                className="rounded-md border px-5 py-2 text-sm"
                                onClick={() => void navigate({ to: "/admin/sales-vouchers" })}
                            >
                                Hủy
                            </button>
                            <button
                                className="rounded-md bg-primary px-5 py-2 text-sm text-primary-foreground disabled:opacity-50"
                                disabled={save.isPending}
                            >
                                {save.isPending ? "Đang lưu..." : "Lưu voucher"}
                            </button>
                        </div>
                    </form>
                ))}
            {mode === "list" && (
                <section className="rounded-xl border bg-card p-5 shadow-sm">
                    <div className="mb-4 grid gap-3 md:grid-cols-[minmax(0,1fr)_200px]">
                        <input
                            className={fieldClass}
                            placeholder="Tìm mã hoặc tên voucher"
                            value={search}
                            onChange={(e) => {
                                setSearch(e.target.value);
                                setPage(1);
                            }}
                        />
                        <select
                            className={fieldClass}
                            value={statusFilter}
                            onChange={(event) => {
                                setStatusFilter(event.target.value);
                                setPage(1);
                            }}
                            aria-label="Lọc trạng thái voucher"
                        >
                            <option value="">Mọi trạng thái</option>
                            <option value="active">Hoạt động</option>
                            <option value="inactive">Tạm dừng</option>
                            <option value="upcoming">Chưa bắt đầu</option>
                            <option value="expired">Hết hạn</option>
                        </select>
                    </div>
                    {list.isPending ? (
                        <LoadingState />
                    ) : list.isError ? (
                        <ErrorState
                            message={errorMessage(list.error)}
                            retry={() => void list.refetch()}
                        />
                    ) : list.data.data.length === 0 ? (
                        <EmptyState
                            message={
                                search || statusFilter
                                    ? "Không tìm thấy voucher phù hợp."
                                    : "Chưa có voucher nào."
                            }
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[650px] text-left text-sm">
                                <thead>
                                    <tr className="border-b">
                                        <th className="p-2">Mã / tên</th>
                                        <th className="p-2">Giảm</th>
                                        <th className="p-2">Điều kiện</th>
                                        <th className="p-2">Hiệu lực</th>
                                        <th className="p-2">Lượt dùng</th>
                                        <th className="p-2">Trạng thái</th>
                                        <th className="p-2">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {list.data.data.map((voucher) => (
                                        <tr key={voucher.id} className="border-b">
                                            <td className="p-2">
                                                <strong>{voucher.code}</strong>
                                                <div>{voucher.name}</div>
                                            </td>
                                            <td className="p-2">
                                                {voucher.discount_type === "percentage"
                                                    ? formatPercentage(voucher.discount_value)
                                                    : `${Number(voucher.discount_value).toLocaleString("vi-VN")}đ`}
                                            </td>
                                            <td className="p-2">
                                                Đơn từ{" "}
                                                {Number(
                                                    voucher.minimum_order_amount,
                                                ).toLocaleString("vi-VN")}
                                                đ
                                            </td>
                                            <td className="p-2">
                                                {voucher.starts_at?.slice(0, 10) ?? "—"} →{" "}
                                                {voucher.ends_at?.slice(0, 10) ?? "—"}
                                            </td>
                                            <td className="p-2">
                                                {voucher.redeemed_count}
                                                {voucher.total_usage_limit === null
                                                    ? ""
                                                    : ` / ${voucher.total_usage_limit}`}
                                            </td>
                                            <td className="p-2">
                                                {voucher.status === "inactive"
                                                    ? "Tạm ngừng"
                                                    : voucher.starts_at &&
                                                        new Date(voucher.starts_at) > new Date()
                                                      ? "Chưa bắt đầu"
                                                      : voucher.ends_at &&
                                                          new Date(voucher.ends_at) < new Date()
                                                        ? "Hết hạn"
                                                        : "Đang hoạt động"}
                                            </td>
                                            <td className="p-2">
                                                <button
                                                    className="text-primary underline"
                                                    disabled={save.isPending}
                                                    onClick={() =>
                                                        void navigate({
                                                            to: "/admin/sales-vouchers/$id/edit",
                                                            params: { id: String(voucher.id) },
                                                        })
                                                    }
                                                >
                                                    Sửa
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <Pagination
                                current={list.data.current_page}
                                last={list.data.last_page}
                                onPage={setPage}
                            />
                        </div>
                    )}
                </section>
            )}
        </main>
    );
}
