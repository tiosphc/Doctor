import { useState } from "react";
import { Link, useNavigate } from "@tanstack/react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { EmptyState, ErrorState, LoadingState, Pagination } from "@/components/common/AsyncState";
import { adminFormLayout } from "@/components/admin/AdminFormLayout";
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
    ProductAdminGuard,
    buttonClass,
    fieldClass,
    secondaryButtonClass,
} from "@/pages/admin/ProductAdminShared";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { dealerApi, dealerKeys } from "./api";
import { AdminDealerTierPanel } from "./AdminDealerTierPanel";
import { AdminDealerWalletPanel } from "./AdminDealerWalletPanel";
import type { DealerAccount, DealerAccountInput, DealerAccountStatus } from "./types";
import { walletDate, walletMoney, walletType } from "./dealerWalletFormat";

const statusText: Record<DealerAccountStatus, string> = {
    active: "Đang hoạt động",
    suspended: "Tạm ngưng",
    inactive: "Ngừng hoạt động",
};
const editFields = [
    ["legal_name", "Tên cơ sở / doanh nghiệp"],
    ["trading_name", "Tên giao dịch"],
    ["contact_name", "Người liên hệ"],
    ["email", "Email"],
    ["phone", "Điện thoại"],
    ["tax_code", "Mã số thuế"],
    ["billing_address_line1", "Địa chỉ"],
    ["billing_address_line2", "Địa chỉ bổ sung"],
    ["city", "Thành phố / quận huyện"],
    ["province", "Tỉnh / thành"],
    ["country", "Quốc gia"],
    ["postal_code", "Mã bưu chính"],
] as const;

export function AdminDealersPage() {
    const navigate = useNavigate();
    const [status, setStatus] = useState<DealerAccountStatus | "">("");
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const filters = { status, search, page };
    const query = useQuery({
        queryKey: dealerKeys.adminList(filters),
        queryFn: () => dealerApi.adminList(filters),
    });
    return (
        <ProductAdminGuard>
            <div className="space-y-6">
                <header>
                    <p className="label-luxury">Đại lý</p>
                    <h1 className="mt-2 text-3xl text-primary">Tài khoản đại lý</h1>
                </header>
                <div className="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2">
                    <input
                        className={fieldClass}
                        aria-label="Tìm đại lý"
                        placeholder="Mã, tên hoặc số điện thoại"
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                    />
                    <select
                        className={fieldClass}
                        aria-label="Lọc trạng thái đại lý"
                        value={status}
                        onChange={(event) => {
                            setStatus(event.target.value as DealerAccountStatus | "");
                            setPage(1);
                        }}
                    >
                        <option value="">Tất cả trạng thái</option>
                        {Object.entries(statusText).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>
                {query.isPending ? (
                    <LoadingState />
                ) : query.isError ? (
                    <ErrorState
                        message={errorMessage(query.error)}
                        retry={() => void query.refetch()}
                    />
                ) : query.data.data.length === 0 ? (
                    <EmptyState message="Không có Dealer Account phù hợp." />
                ) : (
                    <>
                        <div className="overflow-x-auto rounded-xl border bg-card">
                            <table className="w-full min-w-[760px] text-left text-sm">
                                <thead className="bg-muted/60">
                                    <tr>
                                        <th className="p-3">Mã</th>
                                        <th className="p-3">Tên cơ sở</th>
                                        <th className="p-3">Chủ sở hữu</th>
                                        <th className="p-3">Điện thoại</th>
                                        <th className="p-3">Tier</th>
                                        <th className="p-3 text-right">Số dư ví</th>
                                        <th className="p-3">Trạng thái</th>
                                        <th className="p-3">Ngày tạo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {query.data.data.map((dealer) => (
                                        <tr
                                            key={dealer.id}
                                            tabIndex={0}
                                            role="link"
                                            aria-label={`Xem đại lý ${dealer.legal_name}`}
                                            className="cursor-pointer border-t hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-primary"
                                            onClick={() =>
                                                void navigate({
                                                    to: "/admin/dealers/$id",
                                                    search: {},
                                                    params: { id: String(dealer.id) },
                                                })
                                            }
                                            onKeyDown={(event) => {
                                                if (event.key === "Enter" || event.key === " ") {
                                                    event.preventDefault();
                                                    void navigate({
                                                        to: "/admin/dealers/$id",
                                                        search: {},
                                                        params: { id: String(dealer.id) },
                                                    });
                                                }
                                            }}
                                        >
                                            <td className="p-3 font-medium">{dealer.code}</td>
                                            <td className="p-3">{dealer.legal_name}</td>
                                            <td className="p-3">{dealer.owner?.name ?? "—"}</td>
                                            <td className="p-3">{dealer.phone}</td>
                                            <td className="p-3">{dealer.tier?.name ?? "—"}</td>
                                            <td className="p-3 text-right font-medium">
                                                {walletMoney(dealer.wallet_balance ?? "0")}
                                            </td>
                                            <td className="p-3">{statusText[dealer.status]}</td>
                                            <td className="p-3">
                                                {new Date(dealer.created_at).toLocaleDateString(
                                                    "vi-VN",
                                                )}
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

type DetailTab = "overview" | "info" | "tier" | "wallet";

export function AdminDealerDetailPage({
    id,
    initialTab = "overview",
}: {
    id: number;
    initialTab?: DetailTab;
}) {
    const query = useQuery({
        queryKey: dealerKeys.adminDetail(id),
        queryFn: () => dealerApi.adminDetail(id),
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
                <DealerEditor key={id} dealer={query.data.data} initialTab={initialTab} />
            )}
        </ProductAdminGuard>
    );
}

function DealerEditor({ dealer, initialTab }: { dealer: DealerAccount; initialTab: DetailTab }) {
    const client = useQueryClient();
    const [tab, setTab] = useState<DetailTab>(initialTab);
    const [editing, setEditing] = useState(false);
    const wallet = useQuery({
        queryKey: ["admin-dealer-wallet", dealer.id],
        queryFn: () => dealerApi.adminWallet(dealer.id),
    });
    const tier = useQuery({
        queryKey: dealerKeys.adminTier(dealer.id),
        queryFn: () => dealerApi.adminTier(dealer.id),
    });
    const progress = useQuery({
        queryKey: dealerKeys.adminAutoTier(dealer.id),
        queryFn: () => dealerApi.adminAutoTier(dealer.id),
    });
    const recent = useQuery({
        queryKey: ["admin-dealer-wallet-transactions", dealer.id, { page: 1 }],
        queryFn: () => dealerApi.adminWalletTransactions(dealer.id, { page: 1 }),
        enabled: tab === "overview",
    });
    const [form, setForm] = useState<DealerAccountInput>(
        Object.fromEntries(editFields.map(([key]) => [key, dealer[key]])) as DealerAccountInput,
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [notice, setNotice] = useState("");
    const [pendingAction, setPendingAction] = useState<
        "activate" | "suspend" | "inactivate" | null
    >(null);
    const refresh = async () => {
        await client.invalidateQueries({ queryKey: dealerKeys.adminDetail(dealer.id) });
        await client.invalidateQueries({ queryKey: ["admin-dealers"] });
    };
    const save = useMutation({
        mutationFn: () => dealerApi.update(dealer.id, form),
        onSuccess: async () => {
            setNotice("Đã lưu thông tin đại lý.");
            setEditing(false);
            toast.success("Đã lưu thông tin đại lý.");
            setErrors({});
            await refresh();
        },
        onError: (error) => {
            setNotice(errorMessage(error));
            setErrors(firstFieldErrors(error));
            toast.error(`Không thể lưu thông tin đại lý. ${errorMessage(error)}`);
        },
    });
    const transition = useMutation({
        mutationFn: (action: "activate" | "suspend" | "inactivate") =>
            dealerApi.transition(dealer.id, action),
        onSuccess: async (_response, action) => {
            setPendingAction(null);
            const statusMessages = {
                activate: "Đã kích hoạt đại lý.",
                suspend: "Đã tạm ngừng đại lý.",
                inactivate: "Đã ngừng hoạt động đại lý.",
            };
            toast.success(statusMessages[action]);
            setNotice("");
            await refresh();
        },
        onError: (error) => {
            setNotice(errorMessage(error));
            toast.error(errorMessage(error));
        },
    });
    return (
        <div className="space-y-6">
            <header>
                <Link to="/admin/dealers" className="text-sm text-primary underline">
                    ← Danh sách đại lý
                </Link>
                <p className="label-luxury mt-3">{dealer.code}</p>
                <h1 className="mt-2 text-3xl text-primary">{dealer.legal_name}</h1>
                <div className="mt-2 flex flex-wrap gap-2 text-sm">
                    <span className="rounded-full bg-primary/10 px-3 py-1 text-primary">
                        {tier.data?.data.effective_tier?.name ?? "Chưa gán Tier"}
                    </span>
                    <span className="rounded-full bg-muted px-3 py-1">
                        {statusText[dealer.status]}
                    </span>
                </div>
            </header>
            <div className="grid gap-3 sm:grid-cols-3">
                {[
                    ["Số dư ví", wallet.data ? walletMoney(wallet.data.data.balance) : "—"],
                    [
                        "Doanh thu 3 tháng",
                        progress.data ? walletMoney(progress.data.data.net_revenue) : "—",
                    ],
                    ["Tier hiện tại", tier.data?.data.effective_tier?.name ?? "—"],
                ].map(([label, value]) => (
                    <div key={label} className="rounded-xl border bg-card p-4">
                        <p className="text-xs text-muted-foreground">{label}</p>
                        <strong className="mt-2 block text-lg text-primary">{value}</strong>
                    </div>
                ))}
            </div>
            <nav
                aria-label="Chi tiết đại lý"
                className="flex gap-2 overflow-x-auto border-b pb-2 text-sm"
            >
                {(
                    [
                        ["overview", "Tổng quan"],
                        ["info", "Thông tin"],
                        ["tier", "Tier"],
                        ["wallet", "Ví & giao dịch"],
                    ] as const
                ).map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        className={`whitespace-nowrap rounded-md px-4 py-2 ${tab === key ? "bg-primary text-primary-foreground" : "hover:bg-muted"}`}
                        onClick={() => setTab(key)}
                    >
                        {label}
                    </button>
                ))}
            </nav>
            {tab === "overview" && (
                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="space-y-3 rounded-xl border bg-card p-5">
                        <h2 className="text-lg font-semibold text-primary">Tổng quan đại lý</h2>
                        <p>
                            Hạng đang áp dụng:{" "}
                            <strong>{tier.data?.data.effective_tier?.name ?? "—"}</strong>
                        </p>
                        <p>
                            Số dư khả dụng:{" "}
                            <strong>
                                {wallet.data ? walletMoney(wallet.data.data.balance) : "—"}
                            </strong>
                        </p>
                        <p>
                            Doanh thu xét Tier:{" "}
                            <strong>
                                {progress.data ? walletMoney(progress.data.data.net_revenue) : "—"}
                            </strong>
                        </p>
                        {progress.data?.data.next_tier && (
                            <p>
                                Còn {walletMoney(progress.data.data.remaining_to_next ?? "0")} để
                                đạt {progress.data.data.next_tier.name}.
                            </p>
                        )}
                        <button className={secondaryButtonClass} onClick={() => setTab("tier")}>
                            Xem Tier
                        </button>
                    </section>
                    <section className="rounded-xl border bg-card p-5">
                        <h2 className="text-lg font-semibold text-primary">Biến động ví gần đây</h2>
                        {recent.isPending ? (
                            <LoadingState />
                        ) : recent.isError ? (
                            <ErrorState
                                message={errorMessage(recent.error)}
                                retry={() => void recent.refetch()}
                            />
                        ) : recent.data.data.length ? (
                            <div className="mt-3 space-y-2">
                                {recent.data.data.slice(0, 5).map((item) => (
                                    <div
                                        key={item.id}
                                        className="flex justify-between gap-3 border-b pb-2 text-sm"
                                    >
                                        <span>
                                            {walletType(item.type)} · {walletDate(item.created_at)}
                                        </span>
                                        <strong>
                                            {item.direction === "credit" ? "+" : "−"}
                                            {walletMoney(item.amount)}
                                        </strong>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="mt-3 text-sm text-muted-foreground">Chưa có giao dịch.</p>
                        )}
                    </section>
                </div>
            )}
            {tab === "info" && (
                <>
                    <section
                        className={`${adminFormLayout.standard} rounded-xl border bg-card p-5 sm:p-6`}
                    >
                        <div className="flex flex-wrap justify-between gap-2">
                            <h2 className="text-xl text-primary">Thông tin kinh doanh</h2>
                            {!editing && (
                                <button
                                    className={secondaryButtonClass}
                                    onClick={() => setEditing(true)}
                                >
                                    Chỉnh sửa thông tin
                                </button>
                            )}
                        </div>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            {editFields.map(([key, label]) => (
                                <div key={key} className="grid gap-1 text-sm">
                                    <span className="text-muted-foreground">{label}</span>
                                    {editing ? (
                                        <>
                                            <input
                                                className={fieldClass}
                                                type={key === "email" ? "email" : "text"}
                                                value={form[key] ?? ""}
                                                onChange={(event) =>
                                                    setForm((previous) => ({
                                                        ...previous,
                                                        [key]: event.target.value,
                                                    }))
                                                }
                                            />
                                            {errors[key] && (
                                                <span className="text-red-700">{errors[key]}</span>
                                            )}
                                        </>
                                    ) : (
                                        <strong className="font-medium">
                                            {dealer[key] || "—"}
                                        </strong>
                                    )}
                                </div>
                            ))}
                        </div>
                        {editing && (
                            <div className="mt-5 flex flex-wrap gap-2">
                                <button
                                    className={secondaryButtonClass}
                                    onClick={() => {
                                        setEditing(false);
                                        setForm(
                                            Object.fromEntries(
                                                editFields.map(([key]) => [key, dealer[key]]),
                                            ) as DealerAccountInput,
                                        );
                                        setErrors({});
                                    }}
                                >
                                    Hủy
                                </button>
                                <button
                                    className={buttonClass}
                                    disabled={
                                        save.isPending ||
                                        !form.legal_name?.trim() ||
                                        !form.contact_name?.trim() ||
                                        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email ?? "") ||
                                        !/^\+?[0-9\s()-]{8,20}$/.test(form.phone ?? "")
                                    }
                                    onClick={() => save.mutate()}
                                >
                                    {save.isPending ? "Đang lưu..." : "Lưu thay đổi"}
                                </button>
                            </div>
                        )}
                    </section>
                    <section className="rounded-xl border bg-card p-5 sm:p-6">
                        <h2 className="text-xl text-primary">Tài khoản đại lý</h2>
                        <div className="mt-3 overflow-x-auto">
                            <table className="w-full min-w-[560px] text-left text-sm">
                                <thead className="bg-muted/50">
                                    <tr>
                                        <th className="p-2">Tài khoản</th>
                                        <th className="p-2">Vai trò</th>
                                        <th className="p-2">Trạng thái</th>
                                        <th className="p-2">Nguồn</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {dealer.memberships?.map((member) => (
                                        <tr key={member.id} className="border-t">
                                            <td className="p-2">
                                                {member.user_name ?? `User #${member.user_id}`}
                                            </td>
                                            <td className="p-2">
                                                {member.membership_role === "owner"
                                                    ? "Chủ sở hữu"
                                                    : "Thành viên"}
                                            </td>
                                            <td className="p-2">
                                                {member.status === "active"
                                                    ? "Đang hoạt động"
                                                    : "Ngừng hoạt động"}
                                            </td>
                                            <td className="p-2">
                                                <Link
                                                    to="/admin/dealer-applications/$id"
                                                    params={{
                                                        id: String(dealer.source_application_id),
                                                    }}
                                                    className="text-primary underline"
                                                >
                                                    Đơn đăng ký #{dealer.source_application_id}
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </>
            )}
            {tab === "tier" && <AdminDealerTierPanel dealerId={dealer.id} />}
            {tab === "wallet" && <AdminDealerWalletPanel dealerId={dealer.id} />}
            {notice && (
                <p role="status" className="text-sm text-primary">
                    {notice}
                </p>
            )}
            <div className="flex flex-wrap gap-3">
                {dealer.status !== "active" && (
                    <button
                        className={secondaryButtonClass}
                        onClick={() => setPendingAction("activate")}
                    >
                        Kích hoạt
                    </button>
                )}
                {dealer.status === "active" && (
                    <button
                        className={secondaryButtonClass}
                        onClick={() => setPendingAction("suspend")}
                    >
                        Tạm ngưng
                    </button>
                )}
                {dealer.status !== "inactive" && (
                    <button
                        className={secondaryButtonClass}
                        onClick={() => setPendingAction("inactivate")}
                    >
                        Ngừng hoạt động
                    </button>
                )}
            </div>
            <AlertDialog
                open={pendingAction !== null}
                onOpenChange={(open) => {
                    if (!open && !transition.isPending) setPendingAction(null);
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Xác nhận đổi trạng thái?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Quyền truy cập Dealer context sẽ được cập nhật ngay. Quyền Retail và
                            Clinic của User vẫn giữ nguyên.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={transition.isPending}>
                            Quay lại
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={transition.isPending}
                            onClick={(event) => {
                                event.preventDefault();
                                if (pendingAction) transition.mutate(pendingAction);
                            }}
                        >
                            {transition.isPending ? "Đang xử lý..." : "Xác nhận"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
