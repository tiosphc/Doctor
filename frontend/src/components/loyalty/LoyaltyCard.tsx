import { CheckCircle2, Gift } from "lucide-react";
import { ButtonLink } from "@/components/common/Button";
import type { LoyaltySummary } from "@/types";

export function LoyaltyCard({
    summary,
    showWalletLink = false,
}: {
    summary: LoyaltySummary;
    showWalletLink?: boolean;
}) {
    const achieved = summary.achieved_milestones.at(-1) ?? null;
    const next = summary.next_milestone;
    const target = next?.visits ?? achieved?.visits ?? Math.max(1, summary.completed_visits);
    const displayedVisits = Math.min(summary.completed_visits, target);
    const progress = Math.min(100, Math.round((summary.completed_visits / target) * 100));

    return (
        <section className="overflow-hidden rounded-2xl border border-secondary/30 bg-[linear-gradient(145deg,hsl(var(--card)),hsl(var(--muted)))] p-5 shadow-sm sm:p-6 md:p-7">
            <div className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <div className="flex items-center gap-3">
                        <span className="grid size-10 shrink-0 place-items-center rounded-full bg-secondary/15 text-secondary-foreground">
                            <Gift size={19} aria-hidden="true" />
                        </span>
                        <div>
                            <p className="label-luxury">Quyền lợi thành viên</p>
                            <h2 className="mt-1 text-2xl text-primary">Hành trình đồng hành</h2>
                        </div>
                    </div>
                    <p className="mt-5 text-3xl font-semibold text-primary">
                        {displayedVisits} / {target}{" "}
                        <span className="text-base font-normal">lần</span>
                    </p>
                </div>
                {showWalletLink && achieved && (
                    <ButtonLink to="/account/vouchers" variant="outline" className="shrink-0">
                        Xem Voucher
                    </ButtonLink>
                )}
            </div>

            <div
                className="mt-5 h-2.5 w-full overflow-hidden rounded-full bg-primary/10"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={target}
                aria-valuenow={displayedVisits}
                aria-label="Tiến độ quyền lợi thành viên"
            >
                <div
                    className="h-full rounded-full bg-secondary transition-[width]"
                    style={{ width: `${progress}%` }}
                />
            </div>

            {next ? (
                <div className="mt-5 grid gap-1 text-sm leading-6">
                    <p className="font-semibold text-primary">
                        {next.remaining_visits > 0
                            ? `Còn ${next.remaining_visits} lần nữa để nhận Voucher ${next.reward_value}%`
                            : `Đã đủ ${next.visits} lần hoàn thành; phần thưởng sẽ được ghi nhận ở lần hoàn tất tiếp theo.`}
                    </p>
                    <p className="text-muted-foreground">
                        Phần thưởng tiếp theo: Voucher giảm {next.reward_value}%
                    </p>
                </div>
            ) : achieved ? (
                <div className="mt-5 flex items-start gap-3 rounded-xl bg-emerald-50 p-4 text-emerald-800">
                    <CheckCircle2 className="mt-0.5 shrink-0" size={19} aria-hidden="true" />
                    <div className="text-sm leading-6">
                        <p className="font-semibold">Đã đạt mốc {achieved.visits} lần</p>
                        <p>
                            Voucher {achieved.reward_value}% đã được cấp. Bạn đã đạt mốc thành viên
                            hiện tại.
                        </p>
                    </div>
                </div>
            ) : (
                <p className="mt-5 text-sm text-muted-foreground">
                    Hoàn thành các lần sử dụng dịch vụ để nhận quyền lợi thành viên.
                </p>
            )}
        </section>
    );
}
