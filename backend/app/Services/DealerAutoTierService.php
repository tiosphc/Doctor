<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\DealerTierHistory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DealerAutoTierService
{
    public function __construct(
        private readonly DealerNetRevenueService $revenue,
        private readonly DealerTierService $tiers,
    ) {}

    /** @return array<string, mixed> */
    public function upgradeIfEligible(int $accountId): array
    {
        return DB::transaction(function () use ($accountId): array {
            $at = CarbonImmutable::now('Asia/Ho_Chi_Minh');
            $account = DealerAccount::query()->lockForUpdate()->findOrFail($accountId);
            $policy = DB::table('dealer_auto_tier_policies')->where('id', 1)->lockForUpdate()->first();
            if (! $policy?->enabled || $account->status !== DealerAccount::STATUS_ACTIVE
                || $account->current_tier_id === null || $account->currentTier?->status !== 'active'
                || $this->tiers->resolve($account)['override'] !== null) {
                return ['changed' => false, 'reason' => 'not_eligible'];
            }
            $rules = $this->validatedRules();
            $start = $at->startOfMonth()->subMonths(2)->startOfDay();
            $revenue = $this->revenue->forAccount($account, $start, $at);
            $target = $rules->first();
            foreach ($rules as $rule) {
                if (bccomp($revenue['net'], (string) $rule->revenue_threshold, 2) >= 0) {
                    $target = $rule;
                }
            }
            if ($target->sort_order <= $account->currentTier->sort_order) {
                return ['changed' => false, 'reason' => 'threshold_not_reached', 'net_revenue' => $revenue['net']];
            }
            $this->tiers->changeAutomatic($account, $target, $revenue['net'], (int) $policy->version,
                $start, $at, $at->format('Y-m'), DealerTierHistory::SOURCE_AUTOMATIC_UPGRADE);

            return ['changed' => true, 'reason' => 'upgraded', 'tier' => $target->code, 'net_revenue' => $revenue['net']];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function evaluate(int $accountId, bool $apply = false): array
    {
        return DB::transaction(function () use ($accountId, $apply): array {
            $at = CarbonImmutable::now('Asia/Ho_Chi_Minh');
            $periodStart = $at->startOfMonth()->subMonths(2)->startOfDay();
            $periodEnd = $apply ? $at->endOfMonth()->endOfDay() : $at;
            $evaluationPeriod = $at->format('Y-m');
            $monthEnd = $at->day === $at->daysInMonth;
            $account = DealerAccount::query()->when($apply, fn ($query) => $query->lockForUpdate())->findOrFail($accountId);
            $policy = DB::table('dealer_auto_tier_policies')->where('id', 1)
                ->when($apply, fn ($query) => $query->lockForUpdate())->first();
            $enabled = (bool) ($policy?->enabled ?? false);
            $revenue = $this->revenue->forAccount($account, $periodStart, $periodEnd);
            try {
                $rules = $this->validatedRules();
            } catch (HttpResponseException $exception) {
                if ($enabled) {
                    throw $exception;
                }
                $rules = collect();
            }
            $target = $rules->first();
            $next = null;
            foreach ($rules as $index => $rule) {
                if (bccomp($revenue['net'], (string) $rule->revenue_threshold, 2) >= 0) {
                    $target = $rule;
                    $next = $rules->get($index + 1);
                } else {
                    $next = $rule;
                    break;
                }
            }
            $currentTierActive = $account->currentTier?->status === 'active';
            $resolution = $this->tiers->resolve($account);
            $previousEvaluation = $apply ? DB::table('dealer_tier_evaluations')
                ->where('dealer_account_id', $account->id)->where('evaluation_period', $evaluationPeriod)->first() : null;
            $wouldChange = $target !== null && $account->status === DealerAccount::STATUS_ACTIVE
                && $currentTierActive && $account->current_tier_id !== $target->id;
            $direction = $wouldChange ? ($target->sort_order > $account->currentTier->sort_order ? 'upgrade' :
                ($target->sort_order < $account->currentTier->sort_order ? 'downgrade' : 'lateral')) : null;
            $reason = $rules->isEmpty() ? 'rules_invalid' : (! $enabled ? 'disabled' : ($account->status !== DealerAccount::STATUS_ACTIVE ? 'account_inactive' :
                ($account->current_tier_id === null ? 'tier_unassigned' :
                    (! $currentTierActive ? 'current_tier_inactive' :
                        ($apply && ! $monthEnd ? 'not_month_end' :
                            ($previousEvaluation !== null ? 'already_evaluated' :
                                ($resolution['override'] !== null ? 'override_active' :
                                    ($account->current_tier_id === $target->id ? 'current' : 'change_required'))))))));
            $changed = false;
            $previousTierId = $account->current_tier_id;
            if ($apply && $monthEnd && in_array($reason, ['change_required', 'current', 'override_active'], true)) {
                if ($reason === 'change_required') {
                    $this->tiers->changeAutomatic($account, $target, $revenue['net'], (int) $policy->version,
                        $periodStart, $periodEnd, $evaluationPeriod);
                    $account->refresh();
                    $changed = true;
                }
                DB::table('dealer_tier_evaluations')->insert([
                    'dealer_account_id' => $account->id,
                    'evaluation_period' => $evaluationPeriod,
                    'revenue_period_start' => $periodStart->toDateString(),
                    'revenue_period_end' => $periodEnd->toDateString(),
                    'previous_tier_id' => $previousTierId,
                    'target_tier_id' => $target?->id,
                    'settled_amount' => $revenue['settled'],
                    'refunded_amount' => $revenue['refunded'],
                    'returned_amount' => $revenue['returned'],
                    'net_revenue' => $revenue['net'],
                    'result' => $reason === 'override_active' ? 'skipped_override' : ($changed ? 'changed' : 'unchanged'),
                    'policy_version' => (int) $policy->version,
                    'evaluated_at' => $at,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
            $latestHistory = $account->tierHistories()->latest('id')->first();
            $historyStatus = $account->current_tier_id === null ? 'unassigned' : ($latestHistory === null ? 'missing' :
                ($latestHistory->new_tier_id === $account->current_tier_id ? 'consistent' : 'mismatch'));
            $resolution = $this->tiers->resolve($account);

            return [
                'dealer_account_id' => $account->id,
                'enabled' => $enabled,
                'policy_version' => (int) ($policy?->version ?? 0),
                'currency' => 'VND',
                'settled_amount' => $revenue['settled'],
                'refunded_amount' => $revenue['refunded'],
                'returned_amount' => $revenue['returned'],
                'net_revenue' => $revenue['net'],
                'revenue_period_start' => $periodStart->toDateString(),
                'revenue_period_end' => $periodEnd->toDateString(),
                'evaluation_period' => $evaluationPeriod,
                'next_evaluation_at' => $this->nextEvaluationDate($at),
                'current_tier' => $account->currentTier?->only(['id', 'code', 'name']),
                'effective_tier' => $resolution['effective_tier'],
                'active_override' => $resolution['override'],
                'history_status' => $historyStatus,
                'target_tier' => $target?->only(['id', 'code', 'name']),
                'next_tier' => $next?->only(['id', 'code', 'name']),
                'next_threshold' => $next?->revenue_threshold,
                'remaining_to_next' => $next ? bcsub((string) $next->revenue_threshold, $revenue['net'], 2) : null,
                'reason' => $reason,
                'would_change' => $wouldChange,
                'direction' => $direction,
                'changed' => $changed,
            ];
        }, 3);
    }

    /** @return Collection<int, DealerTier> */
    public function validatedRules(): Collection
    {
        $rules = DealerTier::query()->whereIn('code', ['SILVER', 'GOLD', 'DIAMOND'])
            ->where('status', 'active')->orderBy('sort_order')->orderBy('id')->get();
        if ($rules->count() !== 3 || $rules->pluck('code')->all() !== ['SILVER', 'GOLD', 'DIAMOND']
            || $rules->first()->is_default_initial !== true
            || bccomp((string) $rules->first()->revenue_threshold, '0', 2) !== 0) {
            $this->conflict('AUTO_TIER_RULES_INVALID');
        }
        $previous = null;
        foreach ($rules as $rule) {
            if ($rule->revenue_threshold === null || ! preg_match('/^(?:0|[1-9][0-9]*)\.00$/', (string) $rule->revenue_threshold)
                || ($previous !== null && ($rule->sort_order <= $previous->sort_order
                || bccomp((string) $rule->revenue_threshold, (string) $previous->revenue_threshold, 2) <= 0))) {
                $this->conflict('AUTO_TIER_RULES_INVALID');
            }
            $previous = $rule;
        }

        return $rules;
    }

    /** @return array{enabled: bool, version: int, revenue_window_months: int, next_evaluation_at: string} */
    public function policy(): array
    {
        $policy = DB::table('dealer_auto_tier_policies')->where('id', 1)->first();

        return ['enabled' => (bool) ($policy?->enabled ?? false), 'version' => (int) ($policy?->version ?? 0),
            'revenue_window_months' => 3, 'next_evaluation_at' => $this->nextEvaluationDate(CarbonImmutable::now('Asia/Ho_Chi_Minh'))];
    }

    private function nextEvaluationDate(CarbonImmutable $at): string
    {
        return ($at->day === $at->daysInMonth ? $at->addMonthNoOverflow() : $at)
            ->endOfMonth()->toDateString();
    }

    public function setEnabled(bool $enabled, int $actorId): array
    {
        return DB::transaction(function () use ($enabled, $actorId): array {
            $existing = DB::table('dealer_auto_tier_policies')->where('id', 1)->lockForUpdate()->first();
            if ($enabled) {
                $this->validatedRules();
            }
            if ($existing === null) {
                DB::table('dealer_auto_tier_policies')->insert([
                    'id' => 1, 'enabled' => $enabled, 'version' => 1,
                    'enabled_at' => $enabled ? now() : null, 'enabled_by' => $enabled ? $actorId : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif ((bool) $existing->enabled !== $enabled) {
                DB::table('dealer_auto_tier_policies')->where('id', 1)->update([
                    'enabled' => $enabled, 'version' => $existing->version + 1,
                    'enabled_at' => $enabled ? now() : null, 'enabled_by' => $enabled ? $actorId : null,
                    'updated_at' => now(),
                ]);
            }

            return $this->policy();
        }, 3);
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
