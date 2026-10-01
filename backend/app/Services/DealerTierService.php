<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\DealerTierHistory;
use App\Models\DealerTierOverride;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class DealerTierService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function defaultInitial(): DealerTier
    {
        $defaults = DealerTier::query()->where('status', 'active')->where('is_default_initial', true)->lockForUpdate()->limit(2)->get();
        if ($defaults->count() !== 1) {
            $this->conflict('DEALER_INITIAL_TIER_NOT_CONFIGURED');
        }

        return $defaults->first();
    }

    public function assignInitial(DealerAccount $account, ?User $actor = null, string $source = DealerTierHistory::SOURCE_INITIAL): DealerTierHistory
    {
        return DB::transaction(function () use ($account, $actor, $source): DealerTierHistory {
            $locked = DealerAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($locked->current_tier_id !== null) {
                $history = $locked->tierHistories()->whereNull('previous_tier_id')->oldest('id')->first();
                if ($history === null) {
                    $this->conflict('DEALER_TIER_HISTORY_MISSING');
                }

                return $history;
            }
            $tier = $this->defaultInitial();
            $effectiveAt = now();
            $expiresAt = $effectiveAt->copy()->addMonthNoOverflow();
            $locked->update(['current_tier_id' => $tier->id, 'tier_assigned_at' => $effectiveAt, 'tier_expires_at' => $expiresAt]);
            $history = $locked->tierHistories()->create([
                'new_tier_id' => $tier->id,
                'source' => $source,
                'reason' => $source === DealerTierHistory::SOURCE_MIGRATION ? 'Initial Tier assigned during controlled backfill' : null,
                'effective_at' => $effectiveAt,
                'expires_at' => $expiresAt,
                'changed_by' => $actor?->id,
            ]);
            $this->audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_DEALER_TIER, $history, 'Initial Dealer Tier assigned', metadata: ['dealer_account_id' => $locked->id, 'new_tier_id' => $tier->id, 'source' => $source]);

            return $history;
        }, 3);
    }

    public function change(DealerAccount $account, DealerTier $target, string $reason, string $operationKey, User $actor): DealerTierHistory
    {
        return DB::transaction(function () use ($account, $target, $reason, $operationKey, $actor): DealerTierHistory {
            $locked = DealerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $existing = DealerTierHistory::query()->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                if ($existing->dealer_account_id !== $locked->id || $existing->new_tier_id !== $target->id || $existing->reason !== $reason) {
                    $this->conflict('DEALER_TIER_OPERATION_CONFLICT');
                }

                return $existing;
            }
            if (DB::table('dealer_auto_tier_policies')->where('id', 1)->value('enabled')) {
                $this->conflict('AUTO_TIER_POLICY_ENABLED');
            }
            $tier = DealerTier::query()->lockForUpdate()->findOrFail($target->id);
            if ($tier->status !== 'active') {
                $this->conflict('DEALER_TIER_INACTIVE');
            }
            if ($locked->current_tier_id === $tier->id) {
                $this->conflict('DEALER_TIER_ALREADY_CURRENT');
            }
            $previous = $locked->current_tier_id;
            $effectiveAt = now();
            $expiresAt = $effectiveAt->copy()->addMonthNoOverflow();
            $locked->update(['current_tier_id' => $tier->id, 'tier_assigned_at' => $effectiveAt, 'tier_expires_at' => $expiresAt]);
            $history = $locked->tierHistories()->create([
                'previous_tier_id' => $previous, 'new_tier_id' => $tier->id,
                'source' => DealerTierHistory::SOURCE_MANUAL, 'reason' => $reason,
                'operation_key' => $operationKey, 'effective_at' => $effectiveAt, 'expires_at' => $expiresAt, 'changed_by' => $actor->id,
            ]);
            $this->audit->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_DEALER_TIER, $history, 'Dealer Tier changed manually', ['tier_id' => $previous], ['tier_id' => $tier->id], ['dealer_account_id' => $locked->id, 'reason' => $reason, 'operation_key' => $operationKey]);

            return $history;
        }, 3);
    }

    public function changeAutomatic(DealerAccount $account, DealerTier $target, string $netRevenue, int $policyVersion,
        CarbonImmutable $periodStart, CarbonImmutable $periodEnd, string $evaluationPeriod,
        string $source = DealerTierHistory::SOURCE_AUTOMATIC_MONTHLY): DealerTierHistory
    {
        return DB::transaction(function () use ($account, $target, $netRevenue, $policyVersion, $periodStart, $periodEnd, $evaluationPeriod, $source): DealerTierHistory {
            $locked = DealerAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($locked->status !== DealerAccount::STATUS_ACTIVE || $locked->current_tier_id === null
                || $locked->current_tier_id === $target->id || $target->status !== 'active') {
                $this->conflict('AUTO_TIER_CHANGE_NOT_AVAILABLE');
            }
            $previous = $locked->current_tier_id;
            $effectiveAt = now();
            $expiresAt = $effectiveAt->copy()->addMonthNoOverflow();
            $locked->update(['current_tier_id' => $target->id, 'tier_assigned_at' => $effectiveAt, 'tier_expires_at' => $expiresAt]);
            $history = $locked->tierHistories()->create([
                'previous_tier_id' => $previous,
                'new_tier_id' => $target->id,
                'source' => $source,
                'reason' => $source === DealerTierHistory::SOURCE_AUTOMATIC_UPGRADE
                    ? 'Revenue threshold reached' : 'Monthly three-calendar-month revenue evaluation',
                'net_revenue_snapshot' => $netRevenue,
                'policy_version' => $policyVersion,
                'evaluation_period' => $evaluationPeriod,
                'revenue_period_start' => $periodStart->toDateString(),
                'revenue_period_end' => $periodEnd->toDateString(),
                'evaluated_at' => $effectiveAt,
                'effective_at' => $effectiveAt,
                'expires_at' => $expiresAt,
            ]);
            $this->audit->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_DEALER_TIER, $history,
                'Dealer Tier changed automatically at month end', ['tier_id' => $previous],
                ['tier_id' => $target->id], ['dealer_account_id' => $locked->id,
                    'net_revenue' => $netRevenue, 'policy_version' => $policyVersion,
                    'evaluation_period' => $evaluationPeriod]);

            return $history;
        }, 3);
    }

    public function createOverride(DealerAccount $account, DealerTier $target, CarbonImmutable $start, ?CarbonImmutable $end, string $reason, User $actor): DealerTierOverride
    {
        return DB::transaction(function () use ($account, $target, $start, $end, $reason, $actor): DealerTierOverride {
            $locked = DealerAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($locked->current_tier_id === null) {
                $this->conflict('DEALER_TIER_NOT_ASSIGNED');
            }
            $tier = DealerTier::query()->lockForUpdate()->findOrFail($target->id);
            if ($tier->status !== 'active') {
                $this->conflict('DEALER_TIER_INACTIVE');
            }
            $overlap = $locked->tierOverrides()->where('status', DealerTierOverride::STATUS_ACTIVE)
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $start))
                ->when($end !== null, fn ($query) => $query->where('starts_at', '<', $end))
                ->exists();
            if ($overlap) {
                $this->conflict('DEALER_TIER_OVERRIDE_OVERLAP');
            }
            $override = $locked->tierOverrides()->create([
                'tier_id' => $tier->id, 'starts_at' => $start, 'ends_at' => $end,
                'reason' => $reason, 'status' => DealerTierOverride::STATUS_ACTIVE, 'created_by' => $actor->id,
            ]);
            $this->audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_DEALER_TIER, $override, 'Dealer Tier override created', metadata: ['dealer_account_id' => $locked->id, 'tier_id' => $tier->id, 'reason' => $reason]);

            return $override;
        }, 3);
    }

    public function cancelOverride(DealerAccount $account, DealerTierOverride $override, User $actor): DealerTierOverride
    {
        return DB::transaction(function () use ($account, $override, $actor): DealerTierOverride {
            DealerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $locked = DealerTierOverride::query()->where('dealer_account_id', $account->id)->lockForUpdate()->findOrFail($override->id);
            if ($locked->status !== DealerTierOverride::STATUS_ACTIVE) {
                $this->conflict('DEALER_TIER_OVERRIDE_CANCELLED');
            }
            $locked->update(['status' => DealerTierOverride::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $actor->id]);
            $this->audit->log(AuditLogger::ACTION_CANCEL, AuditLogger::MODULE_DEALER_TIER, $locked, 'Dealer Tier override cancelled', metadata: ['dealer_account_id' => $account->id, 'tier_id' => $locked->tier_id]);

            return $locked;
        }, 3);
    }

    /** @return array{base_tier: ?array, effective_tier: ?array, source: string, effective_at: ?string, expires_at: ?string, override: ?array} */
    public function resolve(DealerAccount $account): array
    {
        $base = $account->currentTier;
        $at = now();
        $overrides = $account->tierOverrides()->with('tier')->where('status', DealerTierOverride::STATUS_ACTIVE)
            ->where('starts_at', '<=', $at)->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->limit(2)->get();
        if ($overrides->count() > 1) {
            $this->conflict('DEALER_TIER_OVERRIDE_AMBIGUOUS');
        }
        $override = $overrides->first();
        $baseEffectiveAt = $base ? $account->tierHistories()->latest('id')->value('effective_at') : null;
        $format = static fn (?DealerTier $tier): ?array => $tier ? ['id' => $tier->id, 'code' => $tier->code, 'name' => $tier->name, 'status' => $tier->status] : null;

        return [
            'base_tier' => $format($base),
            'effective_tier' => $format($override?->tier ?? $base),
            'source' => $override ? 'manual_override' : ($base ? 'current' : 'unassigned'),
            'effective_at' => $override?->starts_at?->toIso8601String() ?? $account->tier_assigned_at?->toIso8601String() ?? ($baseEffectiveAt ? Carbon::parse($baseEffectiveAt)->toIso8601String() : null),
            'expires_at' => $override ? $override->ends_at?->toIso8601String() : $account->tier_expires_at?->toIso8601String(),
            'override' => $override ? ['id' => $override->id, 'starts_at' => $override->starts_at->toIso8601String(), 'ends_at' => $override->ends_at?->toIso8601String()] : null,
        ];
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
