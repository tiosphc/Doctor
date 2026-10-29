import { useState } from "react";
import { Link } from "@tanstack/react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import {
    AlertDialog,
    AlertDialogAction,
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
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "@/pages/admin/ProductAdminShared";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { dealerApplicationApi, dealerApplicationKeys } from "./api";
import type { DealerApplication, DealerApplicationStatus } from "./types";

const statusLabels: Record<DealerApplicationStatus, string> = {
    pending: "Chờ duyệt",
    approved: "Đã duyệt",
    rejected: "Từ chối",
    cancelled: "Đã hủy",
};

export function AdminDealerApplicationsPage() {
    const [status, setStatus] = useState<DealerApplicationStatus | "">("");
    const [search, setSearch] = useState("");
    const [from, setFrom] = useState("");
    const [to, setTo] = useState("");
    const [page, setPage] = useState(1);
    const filters = { status, search, from, to, page };
    const query = useQuery({
        queryKey: dealerApplicationKeys.adminList(filters),
        queryFn: () => dealerApplicationApi.adminList(filters),
    });
    return (
        <ProductAdminGuard>
            <div className="space-y-6">
                <header>
                    <p className="label-luxury">Đại lý</p>
                    <h1 className="mt-2 text-3xl text-primary">Đơn đăng ký đại lý</h1>
                </header>
                <div className="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2">
                    <input
                        className={fieldClass}
                        aria-label="Tìm đơn đăng ký"
                        placeholder="Tên cơ sở, người nộp, email hoặc số điện thoại"
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                    />
                    <select
                        className={fieldClass}
                        aria-label="Lọc trạng thái"
                        value={status}
                        onChange={(event) => {
                            setStatus(event.target.value as DealerApplicationStatus | "");
                            setPage(1);
                        }}
                    >
                        <option value="">Tất cả trạng thái</option>
                        {Object.entries(statusLabels).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <label className="grid gap-1 text-sm">
                        Từ ngày
                        <input
                            className={fieldClass}
                            type="date"
                            value={from}
                            onChange={(event) => {
                                setFrom(event.target.value);
                                setPage(1);
                            }}
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Đến ngày
                        <input
                            className={fieldClass}
                            type="date"
                            min={from || undefined}
                            value={to}
                            onChange={(event) => {
                                setTo(event.target.value);
                                setPage(1);
                            }}
                        />
                    </label>
                </div>
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState
                        message={errorMessage(query.error)}
                        retry={() => void query.refetch()}
                    />
                ) : query.data.data.length === 0 ? (
                    <EmptyState message="Không có đơn đăng ký phù hợp." />
                ) : (
                    <>
                        <div className="overflow-x-auto rounded-xl border bg-card">
                            <table className="w-full min-w-[650px] text-left text-sm">
                                <thead className="bg-muted/60">
                                    <tr>
                                        <th className="p-3">Người nộp</th>
                                        <th className="p-3">Cơ sở</th>
                                        <th className="p-3">Điện thoại</th>
                                        <th className="p-3">Ngày gửi</th>
                                        <th className="p-3">Trạng thái</th>
                                        <th className="p-3">Chi tiết</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {query.data.data.map((item) => (
                                        <tr key={item.id} className="border-t">
                                            <td className="p-3">{item.applicant?.name}</td>
                                            <td className="p-3 font-medium">{item.company_name}</td>
                                            <td className="p-3">{item.phone}</td>
                                            <td className="p-3">
                                                {new Date(item.submitted_at).toLocaleDateString(
                                                    "vi-VN",
                                                )}
                                            </td>
                                            <td className="p-3">{statusLabels[item.status]}</td>
                                            <td className="p-3">
                                                <Link
                                                    to="/admin/dealer-applications/$id"
                                                    params={{ id: String(item.id) }}
                                                    className="text-primary underline"
                                                >
                                                    Xem
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination
                            current={query.data.meta.current_page}
                            last={query.data.meta.last_page}
                            onPage={setPage}
                        />
                    </>
                )}
            </div>
        </ProductAdminGuard>
    );
}

export function AdminDealerApplicationDetailPage({ id }: { id: number }) {
    const client = useQueryClient();
    const query = useQuery({
        queryKey: dealerApplicationKeys.adminDetail(id),
        queryFn: () => dealerApplicationApi.adminDetail(id),
    });
    const [approveOpen, setApproveOpen] = useState(false);
    const [rejectOpen, setRejectOpen] = useState(false);
    const [reason, setReason] = useState("");
    const [notice, setNotice] = useState("");
    const [reasonError, setReasonError] = useState("");
    const refresh = async () => {
        await client.invalidateQueries({ queryKey: ["admin-dealer-applications"] });
        await client.invalidateQueries({ queryKey: dealerApplicationKeys.adminDetail(id) });
        await client.invalidateQueries({ queryKey: ["admin-dealers"] });
    };
    const approve = useMutation({
        mutationFn: () => dealerApplicationApi.approve(id),
        onSuccess: async () => {
            setApproveOpen(false);
            setNotice("Đã duyệt đơn đăng ký.");
            await refresh();
        },
        onError: (error) => {
            setNotice(errorMessage(error));
            toast.error(errorMessage(error));
        },
    });
    const reject = useMutation({
        mutationFn: () => dealerApplicationApi.reject(id, reason),
        onSuccess: async () => {
            setRejectOpen(false);
            setNotice("Đã từ chối đơn đăng ký.");
            await refresh();
        },
        onError: (error) => {
            setNotice(errorMessage(error));
            setReasonError(firstFieldErrors(error)["rejection_reason"] ?? errorMessage(error));
            toast.error(errorMessage(error));
        },
    });
    return (
        <ProductAdminGuard>
            {query.isPending ? (
                <LoadingState />
            ) : query.isError ? (
                <ErrorState
                    message={errorMessage(query.error)}
                    retry={() => void query.refetch()}
                />
            ) : (
                <div className="space-y-6">
                    <header>
                        <Link
                            to="/admin/dealer-applications"
                            className="text-sm text-primary underline"
                        >
                            ← Danh sách đơn
                        </Link>
                        <h1 className="mt-3 text-3xl text-primary">Đơn đăng ký #{id}</h1>
                        <p className="mt-2 text-sm">{statusLabels[query.data.data.status]}</p>
                    </header>
                    <ApplicationDetails application={query.data.data} />
                    {notice && (
                        <p role="status" className="text-sm text-primary">
                            {notice}
                        </p>
                    )}
                    {query.data.data.status === "pending" && (
                        <div className="flex flex-wrap gap-3">
                            <button className={buttonClass} onClick={() => setApproveOpen(true)}>
                                Duyệt đơn
                            </button>
                            <button
                                className={secondaryButtonClass}
                                onClick={() => setRejectOpen(true)}
                            >
                                Từ chối
                            </button>
                        </div>
                    )}
                    {query.data.data.approved_account && (
                        <Link
                            to="/admin/dealers/$id"
                            search={{}}
                            params={{ id: String(query.data.data.approved_account.id) }}
                            className="text-primary underline"
                        >
                            Xem tài khoản {query.data.data.approved_account.code}
                        </Link>
                    )}
                    <AlertDialog
                        open={approveOpen}
                        onOpenChange={(open) => !approve.isPending && setApproveOpen(open)}
                    >
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>Duyệt đơn đăng ký?</AlertDialogTitle>
                                <AlertDialogDescription>
                                    Thao tác này tạo một Dealer Account và membership chủ sở hữu cho
                                    người nộp.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel disabled={approve.isPending}>
                                    Quay lại
                                </AlertDialogCancel>
                                <AlertDialogAction
                                    disabled={approve.isPending}
                                    onClick={(event) => {
                                        event.preventDefault();
                                        approve.mutate();
                                    }}
                                >
                                    {approve.isPending ? "Đang duyệt..." : "Xác nhận duyệt"}
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                    <Dialog
                        open={rejectOpen}
                        onOpenChange={(open) => !reject.isPending && setRejectOpen(open)}
                    >
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Từ chối đơn đăng ký</DialogTitle>
                                <DialogDescription>
                                    Nhập lý do để người nộp biết cần bổ sung gì.
                                </DialogDescription>
                            </DialogHeader>
                            <label className="grid gap-2 text-sm">
                                Lý do từ chối *
                                <textarea
                                    className={fieldClass}
                                    rows={4}
                                    minLength={5}
                                    value={reason}
                                    onChange={(event) => {
                                        setReason(event.target.value);
                                        setReasonError("");
                                    }}
                                />
                                {reasonError && <span className="text-red-700">{reasonError}</span>}
                            </label>
                            <DialogFooter>
                                <button
                                    className={secondaryButtonClass}
                                    onClick={() => setRejectOpen(false)}
                                >
                                    Quay lại
                                </button>
                                <button
                                    className={buttonClass}
                                    disabled={reject.isPending || reason.trim().length < 5}
                                    onClick={() => reject.mutate()}
                                >
                                    {reject.isPending ? "Đang từ chối..." : "Xác nhận từ chối"}
                                </button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            )}
        </ProductAdminGuard>
    );
}

function ApplicationDetails({ application }: { application: DealerApplication }) {
    const rows = [
        ["Người nộp", application.applicant?.name],
        ["Email User", application.applicant?.email],
        ["Tên cơ sở", application.company_name],
        ["Tên giao dịch", application.trading_name],
        ["Người liên hệ", application.contact_name],
        ["Email liên hệ", application.email],
        ["Điện thoại", application.phone],
        ["Mã số thuế", application.tax_code],
        ["Loại hình", application.business_type],
        [
            "Địa chỉ",
            [
                application.business_address_line1,
                application.business_address_line2,
                application.city,
                application.province,
                application.country,
                application.postal_code,
            ]
                .filter(Boolean)
                .join(", "),
        ],
        [
            "Dự kiến mua/tháng",
            application.estimated_monthly_purchase
                ? `${new Intl.NumberFormat("vi-VN").format(Number(application.estimated_monthly_purchase))} VND`
                : null,
        ],
        ["Ghi chú", application.note],
        ["Lý do từ chối", application.rejection_reason],
        ["Ngày gửi", new Date(application.submitted_at).toLocaleString("vi-VN")],
        [
            "Ngày xét duyệt",
            application.reviewed_at
                ? new Date(application.reviewed_at).toLocaleString("vi-VN")
                : null,
        ],
        ["Người xét duyệt", application.reviewer?.name],
    ];
    return (
        <section className="rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="text-xl text-primary">Thông tin đăng ký</h2>
            <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                {rows
                    .filter(([, value]) => value)
                    .map(([label, value]) => (
                        <div key={label} className="min-w-0">
                            <dt className="text-xs text-muted-foreground">{label}</dt>
                            <dd className="mt-1 break-words text-sm">{value}</dd>
                        </div>
                    ))}
            </dl>
        </section>
    );
}
