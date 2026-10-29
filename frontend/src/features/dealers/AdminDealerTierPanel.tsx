import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
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
import { ErrorState, LoadingState } from "@/components/common/AsyncState";
import { buttonClass, fieldClass, secondaryButtonClass } from "@/pages/admin/ProductAdminShared";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { productApi } from "@/services/productApi";
import { dealerApi, dealerKeys } from "./api";
import { DealerAutoTierProgress } from "./DealerAutoTierProgress";

const dateText = (value: string | null) =>
    value
        ? new Date(value).toLocaleString("vi-VN", {
              year: "numeric",
              month: "2-digit",
              day: "2-digit",
              hour: "2-digit",
              minute: "2-digit",
          })
        : "Không giới hạn";
const money = (value: string) =>
    `${new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 0 }).format(Number(value))} ₫`;
const sourceText = (source: string) =>
    ({
        initial_assignment: "Khởi tạo",
        manual_change: "Admin đổi hạng",
        manual_override: "Ngoại lệ thủ công",
        automatic_upgrade: "Nâng hạng tự động",
        automatic_downgrade: "Hạ hạng tự động",
        automatic_monthly_evaluation: "Xét hạng cuối tháng",
        automatic_revenue: "Xét hạng tự động",
        migration: "Chuyển dữ liệu",
    })[source] ?? "Hệ thống";

export function AdminDealerTierPanel({ dealerId }: { dealerId: number }) {
    const client = useQueryClient();
    const [changeOpen, setChangeOpen] = useState(false);
    const [overrideOpen, setOverrideOpen] = useState(false);
    const [showAllHistory, setShowAllHistory] = useState(false);
    const [targetId, setTargetId] = useState(0);
    const [reason, setReason] = useState("");
    const [operationKey, setOperationKey] = useState("");
    const [overrideTierId, setOverrideTierId] = useState(0);
    const [startsAt, setStartsAt] = useState("");
    const [endsAt, setEndsAt] = useState("");
    const [overrideReason, setOverrideReason] = useState("");
    const [cancelId, setCancelId] = useState<number | null>(null);
    const [notice, setNotice] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const tier = useQuery({
        queryKey: dealerKeys.adminTier(dealerId),
        queryFn: () => dealerApi.adminTier(dealerId),
    });
    const history = useQuery({
        queryKey: dealerKeys.history(dealerId),
        queryFn: () => dealerApi.tierHistory(dealerId),
    });
    const overrides = useQuery({
        queryKey: dealerKeys.overrides(dealerId),
        queryFn: () => dealerApi.tierOverrides(dealerId),
    });
    const master = useQuery({ queryKey: ["dealer-tiers"], queryFn: productApi.dealerTiers });
    const policy = useQuery({
        queryKey: ["dealer-auto-tier-policy"],
        queryFn: productApi.dealerAutoTierPolicy,
    });
    const progress = useQuery({
        queryKey: dealerKeys.adminAutoTier(dealerId),
        queryFn: () => dealerApi.adminAutoTier(dealerId),
    });
    const refresh = async () => {
        await Promise.all([
            client.invalidateQueries({ queryKey: dealerKeys.adminTier(dealerId) }),
            client.invalidateQueries({ queryKey: dealerKeys.history(dealerId) }),
            client.invalidateQueries({ queryKey: dealerKeys.overrides(dealerId) }),
        ]);
    };
    const change = useMutation({
        mutationFn: () =>
            dealerApi.changeTier(dealerId, {
                tier_id: targetId,
                reason: reason.trim(),
                operation_key: operationKey,
            }),
        onSuccess: async () => {
            setChangeOpen(false);
            setNotice("Đã đổi Tier đại lý.");
            toast.success("Đã đổi Tier đại lý.");
            setErrors({});
            await refresh();
        },
        onError: (error) => {
            setNotice(errorMessage(error));
            setErrors(firstFieldErrors(error));
            toast.error(errorMessage(error));
        },
    });
    const createOverride = useMutation({
        mutationFn: () =>
            dealerApi.createTierOverride(dealerId, {
                tier_id: overrideTierId,
                starts_at: new Date(startsAt).toISOString(),
                ends_at: endsAt ? new Date(endsAt).toISOString() : null,
                reason: overrideReason.trim(),
            }),
        onSuccess: async () => {
            setOverrideOpen(false);
            setNotice("Đã tạo ngoại lệ Tier.");
            toast.success("Đã tạo ngoại lệ Tier.");
            setOverrideTierId(0);
            setStartsAt("");
            setEndsAt("");
            setOverrideReason("");
            setErrors({});
            await refresh();
        },
        onError: (error) => {
            setNotice(errorMessage(error));
            setErrors(firstFieldErrors(error));
            toast.error(errorMessage(error));
        },
    });
    const cancelOverride = useMutation({
        mutationFn: (id: number) => dealerApi.cancelTierOverride(dealerId, id),
        onSuccess: async () => {
            setCancelId(null);
            setNotice("Đã hủy ngoại lệ Tier.");
            toast.success("Đã hủy ngoại lệ Tier.");
            await refresh();
        },
        onError: (error) => {
            setNotice(errorMessage(error));
            toast.error(errorMessage(error));
        },
    });
    if (
        tier.isPending ||
        history.isPending ||
        overrides.isPending ||
        master.isPending ||
        policy.isPending ||
        progress.isPending
    )
        return <LoadingState />;
    if (
        tier.isError ||
        history.isError ||
        overrides.isError ||
        master.isError ||
        policy.isError ||
        progress.isError
    ) {
        return (
            <ErrorState
                message={errorMessage(
                    tier.error ??
                        history.error ??
                        overrides.error ??
                        master.error ??
                        policy.error ??
                        progress.error,
                )}
                retry={() => {
                    void tier.refetch();
                    void history.refetch();
                    void overrides.refetch();
                    void master.refetch();
                    void policy.refetch();
                    void progress.refetch();
                }}
            />
        );
    }
    const current = tier.data.data;
    const revenue = progress.data.data;
    const activeTiers = master.data.data.filter((item) => item.status === "active");
    return (
        <section className="space-y-5 rounded-xl border bg-card p-5 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl text-primary">Hạng đại lý</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Nâng hạng ngay khi đủ doanh thu; xét hạ hạng vào cuối tháng.
                    </p>
                </div>
                {!policy.data.data.enabled && (
                    <button
                        className={secondaryButtonClass}
                        onClick={() => {
                            setTargetId(0);
                            setReason("");
                            setOperationKey(crypto.randomUUID());
                            setErrors({});
                            setChangeOpen(true);
                        }}
                    >
                        Đổi Tier
                    </button>
                )}
                <button
                    className={buttonClass}
                    onClick={() => {
                        setErrors({});
                        setOverrideOpen(true);
                    }}
                >
                    Tạo ngoại lệ Tier
                </button>
            </div>
            <div className="rounded-lg bg-primary/5 p-4">
                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Hạng đang áp dụng
                </p>
                <p className="mt-1 text-2xl font-semibold text-primary">
                    {current.effective_tier?.name ?? "Chưa gán"}{" "}
                    <span className="text-xs font-normal">Đang áp dụng</span>
                </p>
                {revenue.next_tier && revenue.next_threshold ? (
                    <>
                        <p className="mt-3 text-sm">
                            Doanh thu xét hạng 3 tháng: {money(revenue.net_revenue)} /{" "}
                            {money(revenue.next_threshold)}
                        </p>
                        <progress
                            className="mt-2 h-2 w-full"
                            value={Math.min(
                                100,
                                Math.max(
                                    0,
                                    (Number(revenue.net_revenue) / Number(revenue.next_threshold)) *
                                        100,
                                ),
                            )}
                            max={100}
                        />
                        <p className="text-sm">
                            Còn {money(revenue.remaining_to_next ?? "0")} để đạt{" "}
                            {revenue.next_tier.name}.
                        </p>
                    </>
                ) : (
                    <p className="mt-2 text-sm">Đã đạt hạng cao nhất.</p>
                )}
                <p className="mt-3 text-xs text-muted-foreground">
                    Tier hệ thống: {current.base_tier?.name ?? "Chưa gán"}.{" "}
                    {current.override
                        ? `Tier đang áp dụng: ${current.effective_tier?.name}. Ngoại lệ đến: ${dateText(current.override.ends_at)}.`
                        : "Không có ngoại lệ Tier đang áp dụng."}
                </p>
            </div>
            <DealerAutoTierProgress dealerId={dealerId} admin />
            {current.override && (
                <p className="rounded-lg bg-primary/5 p-3 text-sm">
                    Ngoại lệ hiệu lực đến: {dateText(current.override.ends_at)}
                </p>
            )}
            {notice && (
                <p role="status" className="text-sm text-primary">
                    {notice}
                </p>
            )}
            <AlertDialog open={overrideOpen} onOpenChange={setOverrideOpen}>
                <AlertDialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto space-y-3">
                    <h3 className="font-medium text-primary">Tạo ngoại lệ Tier</h3>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="grid gap-1 text-sm">
                            Tier hiệu lực
                            <select
                                className={fieldClass}
                                value={overrideTierId}
                                onChange={(event) => setOverrideTierId(Number(event.target.value))}
                            >
                                <option value={0}>Chọn Tier</option>
                                {activeTiers.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Lý do
                            <input
                                className={fieldClass}
                                value={overrideReason}
                                onChange={(event) => setOverrideReason(event.target.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Bắt đầu
                            <input
                                className={fieldClass}
                                type="datetime-local"
                                value={startsAt}
                                onChange={(event) => setStartsAt(event.target.value)}
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Kết thúc (tùy chọn)
                            <input
                                className={fieldClass}
                                type="datetime-local"
                                value={endsAt}
                                onChange={(event) => setEndsAt(event.target.value)}
                            />
                        </label>
                    </div>
                    {Object.keys(errors).some((key) =>
                        ["starts_at", "ends_at", "tier_id", "reason"].includes(key),
                    ) && <p className="text-sm text-red-700">{Object.values(errors).join(" ")}</p>}
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={createOverride.isPending}>
                            Hủy
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={
                                createOverride.isPending ||
                                !overrideTierId ||
                                !startsAt ||
                                overrideReason.trim().length < 3 ||
                                Boolean(endsAt && new Date(endsAt) <= new Date(startsAt))
                            }
                            onClick={(event) => {
                                event.preventDefault();
                                createOverride.mutate();
                            }}
                        >
                            {createOverride.isPending ? "Đang tạo..." : "Tạo ngoại lệ"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <div className="space-y-3 border-t pt-5">
                <h3 className="font-medium text-primary">Ngoại lệ</h3>
                {overrides.data.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">Chưa có ngoại lệ.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[600px] text-left text-sm">
                            <thead>
                                <tr>
                                    <th className="p-2">Tier</th>
                                    <th className="p-2">Bắt đầu</th>
                                    <th className="p-2">Kết thúc</th>
                                    <th className="p-2">Lý do</th>
                                    <th className="p-2">Trạng thái</th>
                                    <th className="p-2">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                {overrides.data.data.map((item) => (
                                    <tr key={item.id} className="border-t">
                                        <td className="p-2">{item.tier.name}</td>
                                        <td className="p-2">{dateText(item.starts_at)}</td>
                                        <td className="p-2">{dateText(item.ends_at)}</td>
                                        <td className="p-2">{item.reason}</td>
                                        <td className="p-2">
                                            {item.status === "active" ? "Đang cấu hình" : "Đã hủy"}
                                        </td>
                                        <td className="p-2">
                                            {item.status === "active" && (
                                                <button
                                                    className="text-primary underline"
                                                    onClick={() => setCancelId(item.id)}
                                                >
                                                    Hủy
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
            <div className="space-y-3 border-t pt-5">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="font-medium text-primary">Lịch sử Tier</h3>
                    <button
                        className="text-sm text-primary underline"
                        onClick={() => setShowAllHistory(!showAllHistory)}
                    >
                        {showAllHistory ? "Thu gọn" : "Xem tất cả"}
                    </button>
                </div>
                {history.data.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">Chưa có lịch sử.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[680px] text-left text-sm">
                            <thead>
                                <tr>
                                    <th className="p-2">Ngày</th>
                                    <th className="p-2">Từ</th>
                                    <th className="p-2">Đến</th>
                                    <th className="p-2">Nguồn</th>
                                    <th className="p-2">Lý do</th>
                                    <th className="p-2">Người đổi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(showAllHistory
                                    ? history.data.data
                                    : history.data.data.slice(0, 3)
                                ).map((item) => (
                                    <tr key={item.id} className="border-t">
                                        <td className="p-2">{dateText(item.effective_at)}</td>
                                        <td className="p-2">{item.previous_tier?.name ?? "—"}</td>
                                        <td className="p-2">{item.new_tier.name}</td>
                                        <td className="p-2">{sourceText(item.source)}</td>
                                        <td className="p-2">
                                            {item.net_revenue_snapshot
                                                ? `Doanh thu ${money(item.net_revenue_snapshot)}`
                                                : (item.reason ?? "—")}
                                        </td>
                                        <td className="p-2">{item.actor?.name ?? "Hệ thống"}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
            <AlertDialog open={changeOpen} onOpenChange={setChangeOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Đổi Tier đại lý?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Tier cơ sở hiện tại: {current.base_tier?.name ?? "Chưa gán"}. Thay đổi
                            sẽ được ghi vào lịch sử và không ảnh hưởng giá Retail.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <div className="grid gap-3">
                        <label className="grid gap-1 text-sm">
                            Tier mới
                            <select
                                className={fieldClass}
                                value={targetId}
                                onChange={(event) => setTargetId(Number(event.target.value))}
                            >
                                <option value={0}>Chọn Tier</option>
                                {activeTiers.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Lý do
                            <input
                                className={fieldClass}
                                value={reason}
                                onChange={(event) => setReason(event.target.value)}
                            />
                        </label>
                        {errors["reason"] && (
                            <p className="text-sm text-red-700">{errors["reason"]}</p>
                        )}
                    </div>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Quay lại</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={
                                change.isPending ||
                                !targetId ||
                                reason.trim().length < 3 ||
                                targetId === current.base_tier?.id
                            }
                            onClick={(event) => {
                                event.preventDefault();
                                change.mutate();
                            }}
                        >
                            {change.isPending ? "Đang cập nhật..." : "Xác nhận đổi"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <AlertDialog
                open={cancelId !== null}
                onOpenChange={(open) => {
                    if (!open && !cancelOverride.isPending) setCancelId(null);
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Hủy ngoại lệ Tier?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Tier hiệu lực sẽ trở về Tier cơ sở nếu ngoại lệ này đang áp dụng.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={cancelOverride.isPending}>
                            Quay lại
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={cancelOverride.isPending}
                            onClick={(event) => {
                                event.preventDefault();
                                if (cancelId !== null) cancelOverride.mutate(cancelId);
                            }}
                        >
                            {cancelOverride.isPending ? "Đang hủy..." : "Xác nhận hủy"}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </section>
    );
}
