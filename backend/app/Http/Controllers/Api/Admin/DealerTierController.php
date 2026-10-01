<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\DealerTierOverride;
use App\Services\AuditLogger;
use App\Services\DealerAutoTierService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DealerTierController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DealerTier::query()->whereIn('code', ['SILVER', 'GOLD', 'DIAMOND'])->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function autoPolicy(DealerAutoTierService $service): JsonResponse
    {
        return response()->json(['data' => $service->policy()]);
    }

    public function setAutoPolicy(Request $request, DealerAutoTierService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $before = $service->policy();
        $result = $service->setEnabled((bool) $data['enabled'], $request->user()->id);
        if ($before !== $result) {
            $tier = DealerTier::query()->where('is_default_initial', true)->firstOrFail();
            $audit->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_DEALER_TIER, $tier,
                'Automatic Dealer Tier policy changed', $before, $result);
        }

        return response()->json(['data' => $result]);
    }

    public function update(Request $request, DealerTier $tier, AuditLogger $audit, DealerAutoTierService $autoTiers): JsonResponse
    {
        $isPreset = in_array($tier->code, ['SILVER', 'GOLD', 'DIAMOND'], true);
        $data = $request->validate([
            'code' => ['prohibited'],
            'name' => [$isPreset ? 'prohibited' : 'sometimes', 'string', 'max:255'],
            'status' => [$isPreset ? 'prohibited' : 'sometimes', Rule::in(['active', 'inactive'])],
            'sort_order' => [$isPreset ? 'prohibited' : 'sometimes', 'integer', 'min:0', 'max:100000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_default_initial' => [$isPreset ? 'prohibited' : 'sometimes', 'boolean'],
            'revenue_threshold' => $isPreset
                ? ['sometimes', 'required', 'regex:/^(?:0|[1-9][0-9]{0,17})$/']
                : ['sometimes', 'nullable', 'regex:/^(?:0|[1-9][0-9]{0,17})$/'],
        ]);
        $tier = DB::transaction(function () use ($tier, $data, $audit, $autoTiers): DealerTier {
            DB::table('dealer_auto_tier_policies')->where('id', 1)->lockForUpdate()->first();
            if ($autoTiers->policy()['enabled'] && array_intersect(['status', 'sort_order', 'is_default_initial'], array_keys($data))) {
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
            $audit->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_DEALER_TIER, $locked, 'Dealer Tier updated', $before, $locked->only(array_keys($data)));

            return $locked;
        }, 3);

        return response()->json(['data' => $tier]);
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
