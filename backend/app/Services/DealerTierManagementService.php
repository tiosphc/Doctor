<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\DealerTierOverride;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DealerTierManagementService
{
    public function __construct(private readonly AuditLogger $audit, private readonly DealerAutoTierService $autoTiers) {}

    /** @param array<string, mixed> $data */
    public function update(DealerTier $tier, array $data): DealerTier
    {
        return DB::transaction(function () use ($tier, $data): DealerTier {
            DB::table('dealer_auto_tier_policies')->where('id', 1)->lockForUpdate()->first();
            if ($this->autoTiers->policy()['enabled'] && array_intersect(['status', 'sort_order', 'is_default_initial'], array_keys($data))) {
                $this->conflict('AUTO_TIER_POLICY_ENABLED');
            }
            $tiers = DealerTier::query()->lockForUpdate()->get()->keyBy('code');
            $locked = DealerTier::query()->lockForUpdate()->findOrFail($tier->id);
            if (array_key_exists('revenue_threshold', $data) && in_array($locked->code, ['SILVER', 'GOLD', 'DIAMOND'], true)) {
                $silver = $locked->code === 'SILVER' ? $data['revenue_threshold'] : $tiers->get('SILVER')?->revenue_threshold;
                $gold = $locked->code === 'GOLD' ? $data['revenue_threshold'] : $tiers->get('GOLD')?->revenue_threshold;
                $diamond = $locked->code === 'DIAMOND' ? $data['revenue_threshold'] : $tiers->get('DIAMOND')?->revenue_threshold;
                if ($silver === null || bccomp((string) $silver, '0', 2) !== 0) {
                    throw ValidationException::withMessages(['revenue_threshold' => 'Ngưỡng Silver cố định bằng 0 VND.']);
                }
                if ($gold !== null && bccomp((string) $gold, '0', 2) <= 0) {
                    throw ValidationException::withMessages(['revenue_threshold' => 'Ngưỡng Gold phải lớn hơn Silver.']);
                }
                if ($diamond !== null && ($gold === null || bccomp((string) $diamond, (string) $gold, 2) <= 0)) {
                    throw ValidationException::withMessages(['revenue_threshold' => 'Ngưỡng Diamond phải lớn hơn Gold.']);
                }
            }
            if (($data['status'] ?? $locked->status) === 'inactive' && $locked->status === 'active') {
                if (DealerAccount::query()->where('status', DealerAccount::STATUS_ACTIVE)->where('current_tier_id', $locked->id)->exists()
                    || DealerTierOverride::query()->where('tier_id', $locked->id)->where('status', DealerTierOverride::STATUS_ACTIVE)
                        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                        ->whereHas('account', fn ($query) => $query->where('status', DealerAccount::STATUS_ACTIVE))->exists()) {
                    $this->conflict('DEALER_TIER_IN_USE');
                }
            }
            if (($data['status'] ?? $locked->status) === 'active' && ($data['is_default_initial'] ?? $locked->is_default_initial)) {
                if (DealerTier::query()->where('id', '<>', $locked->id)->where('status', 'active')->where('is_default_initial', true)->exists()) {
                    $this->conflict('DEALER_INITIAL_TIER_ALREADY_CONFIGURED');
                }
            }
            $before = $locked->only(array_keys($data));
            $locked->update($data);
            if (array_intersect(['status', 'sort_order', 'is_default_initial', 'revenue_threshold'], array_keys($data))) {
                DB::table('dealer_auto_tier_policies')->where('id', 1)->increment('version');
            }
            $this->audit->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_DEALER_TIER, $locked, 'Dealer Tier updated', $before, $locked->only(array_keys($data)));

            return $locked;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function setAutoPolicy(bool $enabled, int $actorId): array
    {
        $before = $this->autoTiers->policy();
        $result = $this->autoTiers->setEnabled($enabled, $actorId);
        if ($before !== $result) {
            $tier = DealerTier::query()->where('is_default_initial', true)->firstOrFail();
            $this->audit->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_DEALER_TIER, $tier,
                'Automatic Dealer Tier policy changed', $before, $result);
        }

        return $result;
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
