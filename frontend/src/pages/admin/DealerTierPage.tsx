import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Crown } from "lucide-react";
import { adminFormLayout } from "@/components/admin/AdminFormLayout";
import { EmptyState, ErrorState, LoadingState } from "@/components/common/AsyncState";
import { errorMessage, firstFieldErrors } from "@/services/api";
import { productApi } from "@/services/productApi";
import type { DealerTier } from "@/features/dealers/types";
import { ProductAdminGuard, fieldClass, secondaryButtonClass } from "./ProductAdminShared";

const tierColors: Record<string, string> = {
    SILVER: "border-slate-200 bg-slate-50 text-slate-700",
    GOLD: "border-amber-200 bg-amber-50 text-amber-800",
    DIAMOND: "border-cyan-200 bg-cyan-50 text-cyan-800",
};

const thresholdDigits = (value: string | null): string => {
    if (value === null) return "";
    const match = value.match(/^(0|[1-9]\d*)(?:\.00)?$/);
    return match ? (match[1] ?? value) : value;
};

const groupedVnd = (digits: string): string => digits.replace(/\B(?=(\d{3})+(?!\d))/g, ".");

const parseThresholdInput = (value: string): string | null => {
    if (/^\d*$/.test(value)) return value;
    return /^\d{1,3}(?:\.\d{3})+$/.test(value) ? value.replaceAll(".", "") : null;
};

export function DealerTierPage() {
    const client = useQueryClient();
    const tiers = useQuery({ queryKey: ["dealer-tiers"], queryFn: productApi.dealerTiers });
    const policy = useQuery({
        queryKey: ["dealer-auto-tier-policy"],
        queryFn: productApi.dealerAutoTierPolicy,
    });
    const setPolicy = useMutation({
        mutationFn: (enabled: boolean) => productApi.setDealerAutoTierPolicy(enabled),
        onSuccess: async (_response, enabled) => {
            toast.success(enabled ? "Đã bật tự động xét Tier." : "Đã tắt tự động xét Tier.");
            await client.invalidateQueries({ queryKey: ["dealer-auto-tier-policy"] });
        },
        onError: (reason) =>
            toast.error(`Không thể cập nhật tự động xét Tier. ${errorMessage(reason)}`),
    });
    const update = useMutation({
        mutationFn: ({
            id,
            data,
        }: {
            id: number;
            code: string;
            data: Partial<Pick<DealerTier, "description" | "revenue_threshold">>;
        }) => productApi.updateDealerTier(id, data),
        onSuccess: async (_result, variables) => {
            toast.success(
                variables.code === "SILVER"
                    ? "Đã cập nhật mô tả Tier Silver."
                    : `Đã cập nhật ngưỡng Tier ${variables.code === "GOLD" ? "Gold" : "Diamond"}.`,
            );
            await client.invalidateQueries({ queryKey: ["dealer-tiers"] });
        },
        onError: (reason) =>
            toast.error(
                firstFieldErrors(reason)["revenue_threshold"] ??
                    `Không thể cập nhật ngưỡng Tier. ${errorMessage(reason)}`,
            ),
    });

    return (
        <ProductAdminGuard>
            <div className={`${adminFormLayout.standard} space-y-6`}>
                <header>
                    <p className="label-luxury">Sản phẩm & giá</p>
                    <h1 className="mt-2 text-3xl text-primary">Tier đại lý</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Ba hạng cố định: Silver, Gold và Diamond. Đại lý mới bắt đầu ở Silver.
                    </p>
                </header>
                <section className="space-y-3 rounded-xl border bg-card p-5">
                    <h2 className="text-lg font-medium text-primary">
                        Tự động xét Tier theo doanh thu
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Chỉ bật sau khi đã cấu hình ngưỡng tăng dần cho cả ba hạng, bắt đầu từ
                        Silver ở 0 VND.
                    </p>
                    {policy.isPending ? (
                        <LoadingState />
                    ) : policy.isError ? (
                        <ErrorState
                            message={errorMessage(policy.error)}
                            retry={() => void policy.refetch()}
                        />
                    ) : (
                        <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
                            <span>
                                Trạng thái:{" "}
                                <strong>
                                    {policy.data.data.enabled ? "Đang bật" : "Đang tắt"}
                                </strong>
                            </span>
                            <span>
                                Chu kỳ doanh thu: <strong>3 tháng gần nhất</strong>
                            </span>
                            <span>
                                Nâng hạng: <strong>Ngay khi đủ điều kiện</strong>
                            </span>
                            <span>
                                Hạ hạng: <strong>Cuối mỗi tháng</strong>
                                <br />
                                Kỳ xét hạ tiếp theo:{" "}
                                {new Date(
                                    `${policy.data.data.next_evaluation_at}T00:00:00`,
                                ).toLocaleDateString("vi-VN")}
                            </span>
                            <button
                                type="button"
                                className={secondaryButtonClass}
                                disabled={setPolicy.isPending}
                                onClick={() => setPolicy.mutate(!policy.data.data.enabled)}
                            >
                                {setPolicy.isPending
                                    ? "Đang cập nhật..."
                                    : policy.data.data.enabled
                                      ? "Tắt tự động"
                                      : "Bật tự động"}
                            </button>
                        </div>
                    )}
                </section>
                {tiers.isPending ? (
                    <LoadingState />
                ) : tiers.isError ? (
                    <ErrorState
                        message={errorMessage(tiers.error)}
                        retry={() => void tiers.refetch()}
                    />
                ) : tiers.data.data.length === 0 ? (
                    <EmptyState message="Chưa có hạng đại lý được cấu hình." />
                ) : (
                    <div className="grid gap-3 lg:grid-cols-3">
                        {tiers.data.data.map((tier) => (
                            <TierCard
                                key={`${tier.id}-${tier.revenue_threshold}-${tier.description}`}
                                tier={tier}
                                tiers={tiers.data.data}
                                busy={update.isPending}
                                save={(data) =>
                                    update.mutate({ id: tier.id, code: tier.code, data })
                                }
                            />
                        ))}
                    </div>
                )}
            </div>
        </ProductAdminGuard>
    );
}

function TierCard({
    tier,
    tiers,
    busy,
    save,
}: {
    tier: DealerTier;
    tiers: DealerTier[];
    busy: boolean;
    save: (data: Partial<Pick<DealerTier, "description" | "revenue_threshold">>) => void;
}) {
    const isSilver = tier.code === "SILVER";
    const [threshold, setThreshold] = useState(thresholdDigits(tier.revenue_threshold));
    const [focused, setFocused] = useState(false);
    const [validationError, setValidationError] = useState("");
    const [description, setDescription] = useState(tier.description ?? "");

    const submit = () => {
        const cleanDescription = description.trim() || null;
        if (isSilver) {
            save({ description: cleanDescription });
            return;
        }
        if (!/^[1-9]\d{0,17}$/.test(threshold)) {
            setValidationError(
                tier.code === "GOLD"
                    ? "Ngưỡng Gold phải lớn hơn Silver."
                    : "Ngưỡng Diamond phải lớn hơn Gold.",
            );
            return;
        }
        const gold =
            tier.code === "GOLD"
                ? threshold
                : thresholdDigits(
                      tiers.find((item) => item.code === "GOLD")?.revenue_threshold ?? null,
                  );
        const diamond =
            tier.code === "DIAMOND"
                ? threshold
                : thresholdDigits(
                      tiers.find((item) => item.code === "DIAMOND")?.revenue_threshold ?? null,
                  );
        if (
            tier.code === "GOLD" &&
            diamond &&
            /^\d+$/.test(diamond) &&
            BigInt(threshold) >= BigInt(diamond)
        ) {
            setValidationError("Ngưỡng Diamond phải lớn hơn Gold.");
            return;
        }
        if (
            tier.code === "DIAMOND" &&
            (!/^[1-9]\d*$/.test(gold) || BigInt(threshold) <= BigInt(gold))
        ) {
            setValidationError("Ngưỡng Diamond phải lớn hơn Gold.");
            return;
        }
        setValidationError("");
        save({ revenue_threshold: threshold, description: cleanDescription });
    };

    return (
        <div className="grid content-start gap-4 rounded-xl border bg-card p-5">
            <div className="flex flex-wrap items-center gap-2">
                <span
                    className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-bold tracking-[.12em] ${tierColors[tier.code] ?? tierColors["SILVER"]}`}
                >
                    <Crown size={14} aria-hidden="true" /> {tier.code}
                </span>
                {tier.is_default_initial && (
                    <span className="text-xs text-muted-foreground">Hạng ban đầu</span>
                )}
            </div>
            <label className="admin-form-field admin-form-label">
                Ngưỡng doanh thu VND
                <input
                    className={`${fieldClass} max-w-56`}
                    type="text"
                    inputMode="numeric"
                    disabled={isSilver}
                    aria-invalid={Boolean(validationError)}
                    value={isSilver ? "0 ₫" : focused ? threshold : groupedVnd(threshold)}
                    onFocus={() => setFocused(true)}
                    onBlur={() => setFocused(false)}
                    onChange={(event) => {
                        const digits = parseThresholdInput(event.target.value);
                        if (digits !== null) {
                            setThreshold(digits);
                            setValidationError("");
                        }
                    }}
                    placeholder={isSilver ? "0 ₫" : "Nhập ngưỡng doanh thu"}
                />
                {validationError && (
                    <span role="alert" className="text-xs text-red-700">
                        {validationError}
                    </span>
                )}
            </label>
            <label className="admin-form-field admin-form-label">
                Mô tả
                <input
                    className={fieldClass}
                    value={description}
                    onChange={(event) => setDescription(event.target.value)}
                />
            </label>
            <button
                type="button"
                className={secondaryButtonClass}
                disabled={
                    busy || (isSilver && description.trim() === (tier.description ?? "").trim())
                }
                onClick={submit}
            >
                {isSilver ? "Lưu mô tả" : "Lưu cấu hình"}
            </button>
        </div>
    );
}
