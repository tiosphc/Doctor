import { useEffect, useState, type FormEvent } from "react";
import { Link, Navigate } from "@tanstack/react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { EmptyState, ErrorState, LoadingState } from "@/components/common/AsyncState";
import { useAuth } from "@/contexts/AuthContext";
import { ApiError, errorMessage, firstFieldErrors } from "@/services/api";
import { dealerApplicationApi, dealerApplicationKeys } from "./api";
import type { DealerApplicationInput } from "./types";

const fieldClass = "w-full rounded-md border bg-background px-3 py-2 text-sm";
const requiredFields = [
    ["company_name", "Tên cơ sở / doanh nghiệp"],
    ["contact_name", "Người liên hệ"],
    ["email", "Email liên hệ"],
    ["phone", "Số điện thoại"],
    ["business_address_line1", "Địa chỉ"],
    ["city", "Thành phố / quận huyện"],
    ["province", "Tỉnh / thành"],
    ["country", "Quốc gia"],
] as const;

export function DealerApplyPage() {
    const { user, isLoading } = useAuth();
    const client = useQueryClient();
    const query = useQuery({
        queryKey: dealerApplicationKeys.mine(user?.id),
        queryFn: dealerApplicationApi.mine,
        enabled: Boolean(user) && user?.role === "customer",
        retry: false,
    });
    const [form, setForm] = useState<DealerApplicationInput>({
        company_name: "",
        contact_name: user?.name ?? "",
        email: user?.email ?? "",
        phone: user?.phone ?? "",
        business_address_line1: "",
        city: "",
        province: "",
        country: "VN",
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [notice, setNotice] = useState("");
    useEffect(() => {
        if (!user) return;
        setForm((previous) => ({
            ...previous,
            contact_name: previous.contact_name || user.name,
            email: previous.email || user.email,
            phone: previous.phone || user.phone || "",
        }));
    }, [user]);
    const submit = useMutation({
        mutationFn: () => dealerApplicationApi.submit(form),
        onSuccess: async () => {
            setNotice("Đơn đăng ký đã được gửi.");
            setErrors({});
            await client.invalidateQueries({ queryKey: dealerApplicationKeys.mine(user?.id) });
        },
        onError: (reason) => {
            setNotice(errorMessage(reason));
            setErrors(firstFieldErrors(reason));
        },
    });
    if (isLoading) return <LoadingState />;
    if (!user) return <Navigate to="/login" />;
    if (user.role !== "customer") return <Navigate to="/account" />;
    if (query.isPending) return <LoadingState />;
    if (query.isError && !(query.error instanceof ApiError && query.error.status === 404)) {
        return (
            <ErrorState message={errorMessage(query.error)} retry={() => void query.refetch()} />
        );
    }
    const application = query.data?.data;
    const showForm =
        !application || application.status === "rejected" || application.status === "cancelled";
    const set = (key: keyof DealerApplicationInput, value: string) =>
        setForm((previous) => ({ ...previous, [key]: value }));
    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        submit.mutate();
    };

    return (
        <div className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6">
            <header>
                <p className="label-luxury">Đại lý Junie</p>
                <h1 className="mt-2 text-3xl text-primary">Đăng ký đại lý</h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Gửi thông tin cơ sở để Admin xét duyệt. Tài khoản mua Retail của bạn vẫn hoạt
                    động bình thường.
                </p>
            </header>
            {application && (
                <section className="rounded-xl border bg-card p-5" role="status">
                    <h2 className="text-xl text-primary">Trạng thái đăng ký</h2>
                    {application.status === "pending" && (
                        <p className="mt-2">Đơn đăng ký đại lý đang chờ xét duyệt.</p>
                    )}
                    {application.status === "approved" && (
                        <div className="mt-2 space-y-3">
                            <p>Tài khoản đại lý đã được chấp nhận.</p>
                            <Link
                                to="/dealer"
                                className="inline-flex rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground"
                            >
                                Xem hồ sơ đại lý
                            </Link>
                        </div>
                    )}
                    {application.status === "rejected" && (
                        <p className="mt-2">Đơn đã bị từ chối: {application.rejection_reason}</p>
                    )}
                    {application.status === "cancelled" && (
                        <p className="mt-2">Đơn đăng ký đã được hủy.</p>
                    )}
                    <p className="mt-3 text-xs text-muted-foreground">
                        Gửi lúc {new Date(application.submitted_at).toLocaleString("vi-VN")}
                    </p>
                </section>
            )}
            {notice && (
                <p role="alert" className="text-sm text-primary">
                    {notice}
                </p>
            )}
            {showForm ? (
                <form
                    onSubmit={onSubmit}
                    className="space-y-5 rounded-xl border bg-card p-5 sm:p-7"
                >
                    <h2 className="text-xl text-primary">Thông tin cơ sở</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {requiredFields.map(([key, label]) => (
                            <label key={key} className="grid gap-1 text-sm">
                                {label} *
                                <input
                                    className={fieldClass}
                                    type={key === "email" ? "email" : "text"}
                                    required
                                    value={form[key]}
                                    onChange={(event) => set(key, event.target.value)}
                                />
                                {errors[key] && <span className="text-red-700">{errors[key]}</span>}
                            </label>
                        ))}
                        {(
                            [
                                ["trading_name", "Tên giao dịch"],
                                ["tax_code", "Mã số thuế"],
                                ["business_address_line2", "Địa chỉ bổ sung"],
                                ["postal_code", "Mã bưu chính"],
                                ["business_type", "Loại hình kinh doanh"],
                            ] as const
                        ).map(([key, label]) => (
                            <label key={key} className="grid gap-1 text-sm">
                                {label} (không bắt buộc)
                                <input
                                    className={fieldClass}
                                    value={form[key] ?? ""}
                                    onChange={(event) => set(key, event.target.value)}
                                />
                                {errors[key] && <span className="text-red-700">{errors[key]}</span>}
                            </label>
                        ))}
                        <label className="grid gap-1 text-sm">
                            Dự kiến mua hàng mỗi tháng (VND, không bắt buộc)
                            <input
                                className={fieldClass}
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.estimated_monthly_purchase ?? ""}
                                onChange={(event) =>
                                    set("estimated_monthly_purchase", event.target.value)
                                }
                            />
                            {errors["estimated_monthly_purchase"] && (
                                <span className="text-red-700">
                                    {errors["estimated_monthly_purchase"]}
                                </span>
                            )}
                        </label>
                        <label className="grid gap-1 text-sm sm:col-span-2">
                            Ghi chú (không bắt buộc)
                            <textarea
                                className={fieldClass}
                                rows={3}
                                value={form.note ?? ""}
                                onChange={(event) => set("note", event.target.value)}
                            />
                            {errors["note"] && (
                                <span className="text-red-700">{errors["note"]}</span>
                            )}
                        </label>
                    </div>
                    <button
                        type="submit"
                        disabled={submit.isPending}
                        className="rounded-md bg-primary px-5 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50"
                    >
                        {submit.isPending ? "Đang gửi..." : "Gửi đơn đăng ký"}
                    </button>
                </form>
            ) : null}
            {!showForm && application?.status === "pending" && (
                <EmptyState message="Admin sẽ phản hồi sau khi xem xét thông tin." />
            )}
        </div>
    );
}
