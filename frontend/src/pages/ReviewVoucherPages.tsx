import { useState, type FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Gift, Plus, RefreshCw, Star, TicketCheck } from "lucide-react";
import { Button, ButtonLink } from "@/components/common/Button";
import { OperationNotice } from "@/components/common/Feedback";
import { Field, Input } from "@/components/common/Fields";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { ReviewCard } from "@/components/reviews/ReviewExperience";
import { CustomerGuard } from "@/pages/account/AccountPages";
import { AdminGuard, AdminTitle } from "@/pages/admin/AdminPages";
import { StaffGuard } from "@/pages/staff/StaffPages";
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
import { errorMessage, firstFieldErrors } from "@/services/api";
import { doctorApi } from "@/services/doctorApi";
import { reviewApi, voucherApi } from "@/services/reviewVoucherApi";
import { serviceApi } from "@/services/serviceApi";
import type { Voucher, VoucherStatus } from "@/types";

const voucherLabels: Record<VoucherStatus, string> = {
    active: "Còn hiệu lực",
    used: "Đã sử dụng",
    expired: "Đã hết hạn",
    revoked: "Đã thu hồi",
};

export function VouchersPage() {
    const [page, setPage] = useState(1);
    const [status, setStatus] = useState("");
    const query = useQuery({
        queryKey: ["my-vouchers", page, status],
        queryFn: () => voucherApi.mine({ page, ...(status ? { status } : {}) }),
    });
    return (
        <CustomerGuard>
            <div>
                <p className="label-luxury">Quyền lợi của bạn</p>
                <h1 className="mt-2 text-3xl text-primary md:text-4xl">Ưu đãi của tôi</h1>
                <p className="mt-3 text-sm text-muted-foreground">
                    Voucher được cấp từ đánh giá hợp lệ và các mốc quyền lợi thành viên.
                </p>
                <div className="mt-6 flex flex-wrap gap-2">
                    {[
                        ["", "Tất cả"],
                        ["active", "Còn hiệu lực"],
                        ["used", "Đã sử dụng"],
                        ["expired", "Đã hết hạn"],
                    ].map(([value, label]) => (
                        <button
                            key={value}
                            onClick={() => {
                                setStatus(value ?? "");
                                setPage(1);
                            }}
                            className={`focus-premium rounded-full border px-4 py-2 text-sm ${status === value ? "bg-primary text-primary-foreground" : "bg-card text-primary"}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                ) : !query.data.data.length ? (
                    <div className="mt-6">
                        <EmptyState message="Bạn chưa có voucher trong nhóm này." />
                    </div>
                ) : (
                    <div className="mt-6 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                        {query.data.data.map((voucher) => (
                            <VoucherCard key={voucher.id} voucher={voucher} />
                        ))}
                    </div>
                )}
                {query.data && (
                    <Pagination
                        current={query.data.meta.current_page}
                        last={query.data.meta.last_page}
                        onPage={setPage}
                    />
                )}
            </div>
        </CustomerGuard>
    );
}

function VoucherCard({ voucher }: { voucher: Voucher }) {
    const active = voucher.status === "active";
    return (
        <article
            className={`relative overflow-hidden rounded-2xl border p-5 shadow-sm ${active ? "border-secondary/60 bg-[linear-gradient(145deg,hsl(var(--card)),hsl(var(--muted)))]" : "bg-muted/40 opacity-80"}`}
        >
            <div className="absolute -right-7 -top-7 size-24 rounded-full bg-secondary/10" />
            <div className="flex items-start justify-between gap-3">
                <span className="grid size-10 place-items-center rounded-full bg-secondary/15 text-secondary-foreground">
                    <Gift size={20} />
                </span>
                <span
                    className={`rounded-full px-3 py-1 text-xs font-semibold ${active ? "bg-emerald-100 text-emerald-800" : "bg-slate-200 text-slate-700"}`}
                >
                    {voucherLabels[voucher.status]}
                </span>
            </div>
            <p className="mt-5 text-xs font-semibold uppercase tracking-[0.18em] text-secondary-foreground">
                {voucher.source === "loyalty_milestone"
                    ? `Thưởng thành viên · Mốc ${voucher.milestone} lần`
                    : "Ưu đãi đánh giá"}
            </p>
            <p className="mt-2 font-serif text-3xl text-primary">Giảm {Number(voucher.value)}%</p>
            <p className="mt-1 text-sm text-muted-foreground">Cho lần đặt lịch tiếp theo</p>
            <div className="mt-5 grid gap-3 border-t pt-4 text-sm">
                <div>
                    <span className="text-muted-foreground">Mã: </span>
                    <strong className="font-mono text-primary">{voucher.code}</strong>
                </div>
                <div>
                    <span className="text-muted-foreground">Hạn sử dụng: </span>
                    <strong>{new Date(voucher.expires_at).toLocaleDateString("vi-VN")}</strong>
                </div>
            </div>
            {active && (
                <ButtonLink to="/booking" className="mt-5 w-full">
                    <TicketCheck size={16} /> Đặt lịch ngay
                </ButtonLink>
            )}
        </article>
    );
}

export function AdminReviewsPage() {
    const [page, setPage] = useState(1);
    const [rating, setRating] = useState("");
    const [status, setStatus] = useState("");
    const [doctorId, setDoctorId] = useState("");
    const [serviceId, setServiceId] = useState("");
    const queryClient = useQueryClient();
    const doctors = useQuery({
        queryKey: ["doctors", "review-filter"],
        queryFn: () => doctorApi.list(),
    });
    const services = useQuery({
        queryKey: ["services", "review-filter"],
        queryFn: () => serviceApi.list(),
    });
    const query = useQuery({
        queryKey: ["admin-reviews", page, rating, status, doctorId, serviceId],
        queryFn: () =>
            reviewApi.admin({
                page,
                ...(rating ? { rating: Number(rating) } : {}),
                ...(status ? { status } : {}),
                ...(doctorId ? { doctor_id: Number(doctorId) } : {}),
                ...(serviceId ? { service_id: Number(serviceId) } : {}),
            }),
    });
    const moderate = useMutation({
        mutationFn: ({ id, next }: { id: number; next: "published" | "hidden" }) =>
            reviewApi.moderate(id, next),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ["admin-reviews"] }),
    });
    return (
        <AdminGuard>
            <div>
                <AdminTitle
                    title="Đánh giá"
                    description="Kiểm duyệt trạng thái công khai mà không sửa nội dung của khách hàng."
                />
                <div className="mt-6 grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FilterSelect
                        value={rating}
                        onChange={setRating}
                        label="Số sao"
                        options={[
                            ["", "Tất cả số sao"],
                            ...[5, 4, 3, 2, 1].map((v) => [String(v), `${v} sao`]),
                        ]}
                    />
                    <FilterSelect
                        value={status}
                        onChange={setStatus}
                        label="Trạng thái"
                        options={[
                            ["", "Tất cả trạng thái"],
                            ["published", "Đang công khai"],
                            ["hidden", "Đã ẩn"],
                        ]}
                    />
                    <FilterSelect
                        value={doctorId}
                        onChange={setDoctorId}
                        label="Bác sĩ"
                        options={[
                            ["", "Tất cả bác sĩ"],
                            ...(doctors.data?.data ?? []).map((d) => [String(d.id), d.name]),
                        ]}
                    />
                    <FilterSelect
                        value={serviceId}
                        onChange={setServiceId}
                        label="Dịch vụ"
                        options={[
                            ["", "Tất cả dịch vụ"],
                            ...(services.data?.data ?? []).map((s) => [String(s.id), s.name]),
                        ]}
                    />
                </div>
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                ) : !query.data.data.length ? (
                    <div className="mt-6">
                        <EmptyState message="Không có đánh giá phù hợp." />
                    </div>
                ) : (
                    <div className="mt-6 grid gap-4">
                        {query.data.data.map((review) => (
                            <article key={review.id} className="rounded-xl border bg-card p-5">
                                <div className="flex flex-col justify-between gap-4 sm:flex-row">
                                    <div>
                                        <div className="flex gap-1 text-secondary">
                                            {Array.from({ length: 5 }, (_, i) => (
                                                <Star
                                                    key={i}
                                                    size={16}
                                                    fill={
                                                        i < review.rating ? "currentColor" : "none"
                                                    }
                                                />
                                            ))}
                                        </div>
                                        <p className="mt-2 font-semibold text-primary">
                                            {review.reviewer.name} · {review.doctor?.name}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {review.service?.name}
                                        </p>
                                        <p className="mt-3 text-sm leading-6 text-muted-foreground">
                                            {review.comment || "Không có nhận xét."}
                                        </p>
                                    </div>
                                    <Button
                                        variant="outline"
                                        disabled={moderate.isPending}
                                        onClick={() =>
                                            moderate.mutate({
                                                id: review.id,
                                                next:
                                                    review.status === "hidden"
                                                        ? "published"
                                                        : "hidden",
                                            })
                                        }
                                    >
                                        {review.status === "hidden" ? "Công khai" : "Ẩn đánh giá"}
                                    </Button>
                                </div>
                            </article>
                        ))}
                    </div>
                )}
                {query.data && (
                    <Pagination
                        current={query.data.meta.current_page}
                        last={query.data.meta.last_page}
                        onPage={setPage}
                    />
                )}
            </div>
        </AdminGuard>
    );
}

export function AdminVouchersPage() {
    return <AdminVoucherList mode="review" />;
}

export function AdminVoucherManagementPage() {
    return <AdminVoucherList mode="management" />;
}

function AdminVoucherList({ mode }: { mode: "review" | "management" }) {
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("");
    const [notice, setNotice] = useState("");
    const [noticeSuccess, setNoticeSuccess] = useState(false);
    const [createOpen, setCreateOpen] = useState(false);
    const [code, setCode] = useState("");
    const [formNotice, setFormNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [revoking, setRevoking] = useState<Voucher | null>(null);
    const [deleting, setDeleting] = useState<Voucher | null>(null);
    const queryClient = useQueryClient();
    const management = mode === "management";
    const sourceFilter: Voucher["source"] = management ? "admin" : "review_reward";
    const query = useQuery({
        queryKey: ["admin-vouchers", mode, page, search, status],
        queryFn: () =>
            voucherApi.admin({
                page,
                ...(management && search ? { search } : {}),
                ...(status ? { status } : {}),
                source: sourceFilter,
            }),
    });
    const generateCode = useMutation({
        mutationFn: voucherApi.generateAdminCode,
        onSuccess: (response) => {
            setCode(response.code);
            setErrors((current) => ({ ...current, code: "" }));
            setFormNotice("");
        },
        onError: (reason) => setFormNotice(errorMessage(reason)),
    });
    const create = useMutation({
        mutationFn: voucherApi.createAdmin,
        onSuccess: async () => {
            setCreateOpen(false);
            setCode("");
            setErrors({});
            setFormNotice("");
            setNotice("Tạo voucher thành công.");
            setNoticeSuccess(true);
            setPage(1);
            await queryClient.invalidateQueries({ queryKey: ["admin-vouchers"] });
        },
    });
    const revoke = useMutation({
        mutationFn: voucherApi.revoke,
        onSuccess: async () => {
            setRevoking(null);
            setNotice("Thu hồi voucher thành công.");
            setNoticeSuccess(true);
            await queryClient.invalidateQueries({ queryKey: ["admin-vouchers"] });
        },
        onError: (reason) => {
            setRevoking(null);
            setNotice(errorMessage(reason));
            setNoticeSuccess(false);
        },
    });
    const remove = useMutation({
        mutationFn: voucherApi.remove,
        onSuccess: async () => {
            setDeleting(null);
            setNotice("Xóa voucher thành công.");
            setNoticeSuccess(true);
            await queryClient.invalidateQueries({ queryKey: ["admin-vouchers"] });
        },
        onError: (reason) => {
            setDeleting(null);
            setNotice(errorMessage(reason));
            setNoticeSuccess(false);
        },
    });

    async function submitCreate(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setErrors({});
        setFormNotice("");
        const form = new FormData(event.currentTarget);

        try {
            await create.mutateAsync({
                code,
                value: Number(form.get("value")),
                expires_at: String(form.get("expires_at") ?? ""),
            });
        } catch (reason) {
            const fieldErrors = firstFieldErrors(reason);
            setErrors(fieldErrors);
            if (Object.keys(fieldErrors).length === 0) setFormNotice(errorMessage(reason));
        }
    }

    function openCreateDialog() {
        setCode("");
        setErrors({});
        setFormNotice("");
        setCreateOpen(true);
    }

    function changeCreateOpen(open: boolean) {
        if (create.isPending) return;
        setCreateOpen(open);
        if (!open) {
            setCode("");
            setErrors({});
            setFormNotice("");
        }
    }

    const vouchers = query.data?.data ?? [];

    return (
        <AdminGuard>
            <div>
                <AdminTitle
                    title={management ? "Quản lý voucher" : "Voucher đánh giá"}
                    description={
                        management
                            ? "Theo dõi, phát hành và quản lý các voucher sử dụng trong hệ thống."
                            : "Theo dõi các voucher được tự động phát hành sau khi khách hàng hoàn thành đánh giá."
                    }
                    action={
                        management ? (
                            <Button
                                type="button"
                                onClick={openCreateDialog}
                                className="w-full shrink-0 sm:w-auto"
                            >
                                <Plus className="size-4" />
                                Thêm voucher
                            </Button>
                        ) : undefined
                    }
                />
                <OperationNotice
                    message={notice}
                    success={noticeSuccess}
                    onClose={() => setNotice("")}
                    className="mt-4"
                />
                <div
                    className={`mt-6 grid gap-4 rounded-xl border bg-card p-4 shadow-sm ${management ? "sm:grid-cols-[minmax(0,1fr)_14rem]" : "max-w-sm"}`}
                >
                    {management && (
                        <Input
                            value={search}
                            onChange={(event) => {
                                setSearch(event.target.value.toUpperCase());
                                setPage(1);
                            }}
                            placeholder="Tìm theo mã voucher..."
                            aria-label="Tìm theo mã voucher"
                        />
                    )}
                    <FilterSelect
                        value={status}
                        onChange={(value) => {
                            setStatus(value);
                            setPage(1);
                        }}
                        label="Trạng thái"
                        options={[
                            ["", "Tất cả"],
                            ["active", "Còn hiệu lực"],
                            ["used", "Đã sử dụng"],
                            ["expired", "Đã hết hạn"],
                            ["revoked", "Đã thu hồi"],
                        ]}
                    />
                </div>
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <div className="mt-6">
                        <ErrorState
                            message="Không thể tải danh sách voucher. Vui lòng thử lại."
                            retry={() => query.refetch()}
                        />
                    </div>
                ) : vouchers.length === 0 ? (
                    management ? (
                        <div className="mt-6 rounded-xl border bg-card p-8 text-center shadow-sm">
                            <p className="font-semibold text-primary">Chưa có voucher nào.</p>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Bạn có thể tạo voucher mới để sử dụng trong hệ thống.
                            </p>
                            <Button type="button" onClick={openCreateDialog} className="mt-5">
                                <Plus className="size-4" />
                                Thêm voucher
                            </Button>
                        </div>
                    ) : (
                        <div className="mt-6">
                            <EmptyState message="Chưa có voucher đánh giá." />
                        </div>
                    )
                ) : (
                    <>
                        <div className="mt-6 hidden overflow-x-auto rounded-xl border bg-card shadow-sm md:block">
                            <table className="w-full min-w-[760px] text-sm">
                                <thead className="bg-muted/45">
                                    <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                                        {!management && <th className="p-4">Khách hàng</th>}
                                        <th className="p-4">Mã</th>
                                        <th className="p-4">Giá trị</th>
                                        <th className="p-4">Hạn dùng</th>
                                        <th className="p-4">Trạng thái</th>
                                        <th className="p-4">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {vouchers.map((voucher) => (
                                        <tr
                                            key={voucher.id}
                                            className="border-b transition-colors last:border-0 hover:bg-muted/25"
                                        >
                                            {!management && (
                                                <td className="p-4">
                                                    <strong className="text-primary">
                                                        {voucher.customer?.name}
                                                    </strong>
                                                    <br />
                                                    <span className="text-xs text-muted-foreground">
                                                        {voucher.customer?.email}
                                                    </span>
                                                </td>
                                            )}
                                            <td className="p-4 font-mono">{voucher.code}</td>
                                            <td className="p-4">{Number(voucher.value)}%</td>
                                            <td className="p-4">
                                                {new Date(voucher.expires_at).toLocaleDateString(
                                                    "vi-VN",
                                                )}
                                            </td>
                                            <td className="p-4">{voucherLabels[voucher.status]}</td>
                                            <td className="p-4">
                                                <div className="flex flex-wrap gap-2">
                                                    {voucher.status === "active" && (
                                                        <Button
                                                            variant="outline"
                                                            className="min-h-9 px-3 text-red-700"
                                                            disabled={revoke.isPending}
                                                            onClick={() => setRevoking(voucher)}
                                                        >
                                                            Thu hồi
                                                        </Button>
                                                    )}
                                                    {management && voucher.status !== "used" && (
                                                        <Button
                                                            variant="ghost"
                                                            className="min-h-9 px-3 text-red-700"
                                                            disabled={remove.isPending}
                                                            onClick={() => setDeleting(voucher)}
                                                        >
                                                            Xóa
                                                        </Button>
                                                    )}
                                                    {voucher.status === "used" && (
                                                        <span className="py-2 text-xs font-semibold text-muted-foreground">
                                                            Đã sử dụng
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="mt-6 grid gap-3 md:hidden">
                            {vouchers.map((voucher) => (
                                <VoucherAdminCard
                                    key={voucher.id}
                                    voucher={voucher}
                                    showCustomer={!management}
                                    allowDelete={management}
                                    onRevoke={setRevoking}
                                    onDelete={setDeleting}
                                />
                            ))}
                        </div>
                    </>
                )}
                {query.data && (
                    <Pagination
                        current={query.data.meta.current_page}
                        last={query.data.meta.last_page}
                        onPage={setPage}
                    />
                )}
                {management && (
                    <CreateAdminVoucherDialog
                        key={createOpen ? "open" : "closed"}
                        open={createOpen}
                        code={code}
                        errors={errors}
                        notice={formNotice}
                        creating={create.isPending}
                        generating={generateCode.isPending}
                        onOpenChange={changeCreateOpen}
                        onCodeChange={setCode}
                        onGenerate={() => generateCode.mutate()}
                        onSubmit={submitCreate}
                        onClearNotice={() => setFormNotice("")}
                    />
                )}
                <AlertDialog
                    open={revoking !== null}
                    onOpenChange={(open) => !open && !revoke.isPending && setRevoking(null)}
                >
                    <AlertDialogContent className="w-[calc(100%-2rem)] max-w-md rounded-2xl">
                        <AlertDialogHeader>
                            <AlertDialogTitle>Thu hồi voucher?</AlertDialogTitle>
                            <AlertDialogDescription className="leading-6">
                                Voucher <strong>{revoking?.code}</strong> sẽ không thể tiếp tục được
                                sử dụng.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={revoke.isPending}>Hủy</AlertDialogCancel>
                            <Button
                                type="button"
                                className="border-red-700 bg-red-700 hover:bg-red-800"
                                disabled={!revoking || revoke.isPending}
                                onClick={() => revoking && revoke.mutate(revoking.id)}
                            >
                                {revoke.isPending ? "Đang thu hồi..." : "Thu hồi voucher"}
                            </Button>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
                <AlertDialog
                    open={deleting !== null}
                    onOpenChange={(open) => !open && !remove.isPending && setDeleting(null)}
                >
                    <AlertDialogContent className="w-[calc(100%-2rem)] max-w-md rounded-2xl">
                        <AlertDialogHeader>
                            <AlertDialogTitle>Xóa voucher?</AlertDialogTitle>
                            <AlertDialogDescription asChild>
                                <div className="space-y-3 text-sm leading-6 text-muted-foreground">
                                    <p>Bạn có chắc muốn xóa voucher này?</p>
                                    <div className="rounded-xl border bg-muted/30 p-4">
                                        <p className="font-mono font-semibold text-primary">
                                            {deleting?.code}
                                        </p>
                                        <p>Giá trị: {Number(deleting?.value ?? 0)}%</p>
                                        <p>
                                            Hạn sử dụng:{" "}
                                            {deleting ? formatVoucherDate(deleting) : ""}
                                        </p>
                                    </div>
                                    <p>Thao tác này không thể hoàn tác trực tiếp.</p>
                                </div>
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={remove.isPending}>Hủy</AlertDialogCancel>
                            <Button
                                type="button"
                                className="border-red-700 bg-red-700 hover:bg-red-800"
                                disabled={!deleting || remove.isPending}
                                onClick={() => deleting && remove.mutate(deleting.id)}
                            >
                                {remove.isPending ? "Đang xóa..." : "Xóa voucher"}
                            </Button>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>
        </AdminGuard>
    );
}

function CreateAdminVoucherDialog({
    open,
    code,
    errors,
    notice,
    creating,
    generating,
    onOpenChange,
    onCodeChange,
    onGenerate,
    onSubmit,
    onClearNotice,
}: {
    open: boolean;
    code: string;
    errors: Record<string, string>;
    notice: string;
    creating: boolean;
    generating: boolean;
    onOpenChange: (open: boolean) => void;
    onCodeChange: (code: string) => void;
    onGenerate: () => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    onClearNotice: () => void;
}) {
    const [expiresAt, setExpiresAt] = useState("");

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-xl gap-0 overflow-y-auto rounded-2xl p-0 shadow-2xl">
                <div className="border-b bg-muted/35 px-5 py-5 sm:px-7">
                    <DialogHeader className="pr-8">
                        <div className="mb-2 grid size-11 place-items-center rounded-full bg-primary text-primary-foreground">
                            <TicketCheck className="size-5" />
                        </div>
                        <DialogTitle className="font-serif text-2xl text-primary sm:text-3xl">
                            Thêm voucher
                        </DialogTitle>
                        <DialogDescription className="leading-6">
                            Tạo voucher giảm giá dùng chung trong hệ thống.
                        </DialogDescription>
                    </DialogHeader>
                </div>
                <form onSubmit={onSubmit}>
                    <div className="grid gap-5 px-5 py-6 sm:px-7">
                        <Field label="Mã voucher *" error={errors["code"]}>
                            <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                                <Input
                                    name="code"
                                    value={code}
                                    onChange={(event) =>
                                        onCodeChange(event.target.value.toUpperCase())
                                    }
                                    placeholder="JUNIE10"
                                    autoComplete="off"
                                    maxLength={32}
                                    autoFocus
                                    required
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="rounded-md px-4"
                                    disabled={generating || creating}
                                    onClick={onGenerate}
                                >
                                    <RefreshCw
                                        className={`size-4 ${generating ? "animate-spin" : ""}`}
                                    />
                                    {generating ? "Đang tạo..." : "Tạo mã tự động"}
                                </Button>
                            </div>
                        </Field>
                        <Field label="Giá trị giảm (%) *" error={errors["value"]}>
                            <select
                                name="value"
                                defaultValue=""
                                className="focus-premium min-h-12 w-full rounded-md border border-input bg-card px-4 text-sm outline-none transition focus:border-primary focus:ring-1 focus:ring-primary"
                                required
                            >
                                <option value="" disabled>
                                    Chọn giá trị giảm
                                </option>
                                {[5, 10, 15, 20, 25, 30, 50].map((value) => (
                                    <option key={value} value={value}>
                                        {value}%
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Hạn sử dụng *" error={errors["expires_at"]}>
                            <div className="grid gap-3">
                                <Input
                                    name="expires_at"
                                    type="date"
                                    value={expiresAt}
                                    onChange={(event) => setExpiresAt(event.target.value)}
                                    min={localDateInputValue(new Date())}
                                    required
                                />
                                <div>
                                    <p className="mb-2 text-xs font-normal text-muted-foreground">
                                        Hoặc chọn nhanh thời hạn
                                    </p>
                                    <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
                                        {[1, 2, 3, 5, 6, 12].map((months) => (
                                            <button
                                                key={months}
                                                type="button"
                                                onClick={() =>
                                                    setExpiresAt(dateAfterMonths(months))
                                                }
                                                className={`focus-premium min-h-10 rounded-lg border px-2 text-xs font-semibold transition-colors ${expiresAt === dateAfterMonths(months) ? "border-primary bg-primary text-primary-foreground" : "bg-card text-primary hover:bg-muted"}`}
                                            >
                                                {months} tháng
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </Field>
                        {notice && (
                            <OperationNotice
                                message={notice}
                                success={false}
                                onClose={onClearNotice}
                            />
                        )}
                    </div>
                    <DialogFooter className="gap-3 border-t bg-muted/20 px-5 py-4 sm:px-7 sm:space-x-0">
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={creating}
                            onClick={() => onOpenChange(false)}
                        >
                            Hủy
                        </Button>
                        <Button type="submit" disabled={creating || generating}>
                            <Plus className="size-4" />
                            {creating ? "Đang tạo..." : "Tạo voucher"}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function VoucherAdminCard({
    voucher,
    showCustomer,
    allowDelete,
    onRevoke,
    onDelete,
}: {
    voucher: Voucher;
    showCustomer: boolean;
    allowDelete: boolean;
    onRevoke: (voucher: Voucher) => void;
    onDelete: (voucher: Voucher) => void;
}) {
    return (
        <article className="rounded-xl border bg-card p-4 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="font-mono font-semibold text-primary">{voucher.code}</p>
                    {showCustomer && (
                        <p className="mt-1 text-sm text-muted-foreground">
                            {voucher.customer?.name} · {voucher.customer?.email}
                        </p>
                    )}
                </div>
                <span className="rounded-full bg-muted px-3 py-1 text-xs font-semibold">
                    {voucherLabels[voucher.status]}
                </span>
            </div>
            <dl className="mt-4 grid grid-cols-2 gap-3 border-t pt-4 text-sm">
                <div>
                    <dt className="text-muted-foreground">Giá trị</dt>
                    <dd className="mt-1 font-semibold">{Number(voucher.value)}%</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Hạn sử dụng</dt>
                    <dd className="mt-1 font-semibold">{formatVoucherDate(voucher)}</dd>
                </div>
            </dl>
            <div className="mt-4 flex flex-wrap gap-2 border-t pt-4">
                {voucher.status === "active" && (
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-10 flex-1 text-red-700"
                        onClick={() => onRevoke(voucher)}
                    >
                        Thu hồi
                    </Button>
                )}
                {allowDelete && voucher.status !== "used" && (
                    <Button
                        type="button"
                        variant="ghost"
                        className="min-h-10 flex-1 text-red-700"
                        onClick={() => onDelete(voucher)}
                    >
                        Xóa
                    </Button>
                )}
                {voucher.status === "used" && (
                    <span className="text-sm font-semibold text-muted-foreground">Đã sử dụng</span>
                )}
            </div>
        </article>
    );
}

function formatVoucherDate(voucher: Voucher): string {
    return new Date(voucher.expires_at).toLocaleDateString("vi-VN");
}

function localDateInputValue(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
}

function dateAfterMonths(months: number): string {
    const today = new Date();
    const target = new Date(today.getFullYear(), today.getMonth() + months, 1);
    const lastDay = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();
    target.setDate(Math.min(today.getDate(), lastDay));
    return localDateInputValue(target);
}

export function DoctorReviewsPage() {
    const [page, setPage] = useState(1);
    const query = useQuery({
        queryKey: ["doctor-portal-reviews", page],
        queryFn: () => reviewApi.doctorPortal(page),
    });
    return (
        <StaffGuard role="doctor">
            <div>
                <p className="label-luxury">Phản hồi khách hàng</p>
                <h1 className="mt-2 text-3xl text-primary md:text-4xl">Đánh giá về tôi</h1>
                <p className="mt-3 text-sm text-muted-foreground">
                    Chỉ hiển thị các đánh giá đang được công khai. Bác sĩ có quyền xem, không có
                    quyền chỉnh sửa.
                </p>
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState message={errorMessage(query.error)} retry={() => query.refetch()} />
                ) : !query.data.data.length ? (
                    <div className="mt-6">
                        <EmptyState message="Chưa có đánh giá công khai." />
                    </div>
                ) : (
                    <div className="mt-6 grid gap-4 md:grid-cols-2">
                        {query.data.data.map((review) => (
                            <ReviewCard key={review.id} review={review} />
                        ))}
                    </div>
                )}
                {query.data && (
                    <Pagination
                        current={query.data.meta.current_page}
                        last={query.data.meta.last_page}
                        onPage={setPage}
                    />
                )}
            </div>
        </StaffGuard>
    );
}

function FilterSelect({
    value,
    onChange,
    label,
    options,
}: {
    value: string;
    onChange: (value: string) => void;
    label: string;
    options: string[][];
}) {
    return (
        <label className="grid gap-2 text-xs font-semibold text-muted-foreground">
            {label}
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="focus-premium h-11 rounded-md border bg-background px-3 text-sm font-normal text-foreground"
            >
                {options.map(([optionValue, optionLabel]) => (
                    <option key={optionValue ?? ""} value={optionValue ?? ""}>
                        {optionLabel ?? ""}
                    </option>
                ))}
            </select>
        </label>
    );
}
