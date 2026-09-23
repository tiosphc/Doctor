<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\LoyaltyMilestoneRewardNotification;
use Illuminate\Support\Facades\DB;

class LoyaltyService
{
    public function __construct(private readonly VoucherService $voucherService) {}

    /**
     * @return array{
     *     completed_visits: int,
     *     next_milestone: array{visits: int, reward_type: string, reward_value: int, remaining_visits: int}|null,
     *     achieved_milestones: list<array{visits: int, reward_type: string, reward_value: int}>
     * }
     */
    public function summary(User $customer): array
    {
        $completedVisits = $customer->appointments()
            ->where('status', Appointment::STATUS_COMPLETED)
            ->count();
        $issuedMilestones = $customer->vouchers()
            ->where('source', Voucher::SOURCE_LOYALTY_MILESTONE)
            ->pluck('source_id')
            ->map(fn (mixed $milestone): int => (int) $milestone)
            ->all();
        $achievedMilestones = [];
        $nextMilestone = null;

        foreach ($this->milestones() as $visits => $reward) {
            $milestone = $this->milestoneSummary($visits, $reward);

            if (in_array($visits, $issuedMilestones, true)) {
                $achievedMilestones[] = $milestone;

                continue;
            }

            if ($nextMilestone === null) {
                $nextMilestone = [
                    ...$milestone,
                    'remaining_visits' => max(0, $visits - $completedVisits),
                ];
            }
        }

        return [
            'completed_visits' => $completedVisits,
            'next_milestone' => $nextMilestone,
            'achieved_milestones' => $achievedMilestones,
        ];
    }

    /** @return list<Voucher> */
    public function evaluateAfterCompletion(User $customer): array
    {
        if (! (bool) config('loyalty.enabled') || ! $customer->isCustomer()) {
            return [];
        }

        $issuedVouchers = DB::transaction(function () use ($customer): array {
            $lockedCustomer = User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $completedVisits = $lockedCustomer->appointments()
                ->where('status', Appointment::STATUS_COMPLETED)
                ->count();
            $issuedMilestones = $lockedCustomer->vouchers()
                ->where('source', Voucher::SOURCE_LOYALTY_MILESTONE)
                ->pluck('source_id')
                ->map(fn (mixed $milestone): int => (int) $milestone)
                ->all();
            $vouchers = [];

            foreach ($this->milestones() as $visits => $reward) {
                if ($visits > $completedVisits || in_array($visits, $issuedMilestones, true)) {
                    continue;
                }

                $voucher = $this->voucherService->issueLoyaltyReward(
                    $lockedCustomer,
                    $visits,
                    (int) $reward['value'],
                    max(1, (int) config('loyalty.voucher_expiry_days')),
                );

                if ($voucher !== null) {
                    $vouchers[] = $voucher;
                    $issuedMilestones[] = $visits;
                }
            }

            return $vouchers;
        }, 3);

        foreach ($issuedVouchers as $voucher) {
            $customer->notify(new LoyaltyMilestoneRewardNotification($voucher, (int) $voucher->source_id));
        }

        return $issuedVouchers;
    }

    /** @return array<int, array{type: string, value: int}> */
    private function milestones(): array
    {
        return collect(config('loyalty.milestones', []))
            ->mapWithKeys(fn (mixed $reward, int|string $visits): array => [
                (int) $visits => [
                    'type' => (string) ($reward['type'] ?? Voucher::TYPE_PERCENTAGE),
                    'value' => max(1, min(100, (int) ($reward['value'] ?? 0))),
                ],
            ])
            ->filter(fn (array $reward, int $visits): bool => $visits > 0
                && $reward['type'] === Voucher::TYPE_PERCENTAGE
                && $reward['value'] > 0)
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array{type: string, value: int}  $reward
     * @return array{visits: int, reward_type: string, reward_value: int}
     */
    private function milestoneSummary(int $visits, array $reward): array
    {
        return [
            'visits' => $visits,
            'reward_type' => $reward['type'],
            'reward_value' => $reward['value'],
        ];
    }
}
