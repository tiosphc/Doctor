<?php

namespace Database\Seeders;

use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\DealerTierOverride;
use App\Models\PriceList;
use App\Models\User;
use App\Services\DealerTierService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class DealerTierPresetSeeder extends Seeder
{
    private const TIERS = [
        'SILVER' => ['name' => 'Silver', 'sort_order' => 1, 'is_default_initial' => true, 'revenue_threshold' => '0'],
        'GOLD' => ['name' => 'Gold', 'sort_order' => 2, 'is_default_initial' => false],
        'DIAMOND' => ['name' => 'Diamond', 'sort_order' => 3, 'is_default_initial' => false],
    ];

    public function run(DealerTierService $tierService): void
    {
        DB::transaction(function () use ($tierService): void {
            $existing = DealerTier::query()->lockForUpdate()->get();
            $unexpected = $existing->first(fn (DealerTier $tier): bool => ! in_array($tier->code, [...array_keys(self::TIERS), 'DEMO-INITIAL'], true));
            if ($unexpected !== null) {
                throw new RuntimeException('Review existing Dealer tiers before applying the Silver/Gold/Diamond preset.');
            }

            $legacy = $existing->firstWhere('code', 'DEMO-INITIAL');
            if ($legacy !== null) {
                if ((bool) (DB::table('dealer_auto_tier_policies')->where('id', 1)->value('enabled') ?? false)) {
                    throw new RuntimeException('Disable Automatic Dealer Tier before archiving the demo Tier.');
                }
                if (DealerTierOverride::query()->where('tier_id', $legacy->id)->where('status', DealerTierOverride::STATUS_ACTIVE)->exists()) {
                    throw new RuntimeException('Review active demo Tier overrides before applying the preset.');
                }
                $legacy->update(['is_default_initial' => false]);
            }

            $preset = [];
            foreach (self::TIERS as $code => $attributes) {
                $tier = DealerTier::query()->firstOrCreate(['code' => $code], [
                    ...$attributes, 'status' => 'active',
                ]);
                $tier->update([...$attributes, 'status' => 'active']);
                $preset[$code] = $tier;
            }

            if ($legacy === null || $legacy->status === 'inactive') {
                return;
            }

            if (PriceList::query()->where('dealer_tier_id', $legacy->id)->exists()
                && PriceList::query()->where('dealer_tier_id', $preset['SILVER']->id)->exists()) {
                throw new RuntimeException('Review overlapping Silver and demo Dealer price lists before applying the preset.');
            }
            if (Schema::hasTable('sales_promotion_dealer_tiers')) {
                $legacyPromotions = DB::table('sales_promotion_dealer_tiers')->where('dealer_tier_id', $legacy->id)->pluck('sales_promotion_id');
                if (DB::table('sales_promotion_dealer_tiers')->where('dealer_tier_id', $preset['SILVER']->id)
                    ->whereIn('sales_promotion_id', $legacyPromotions)->exists()) {
                    throw new RuntimeException('Review overlapping Silver and demo promotion targets before applying the preset.');
                }
                DB::table('sales_promotion_dealer_tiers')->where('dealer_tier_id', $legacy->id)
                    ->update(['dealer_tier_id' => $preset['SILVER']->id]);
            }
            $accounts = DealerAccount::query()->where('current_tier_id', $legacy->id)->orderBy('id')->get();
            $actor = $accounts->isEmpty() ? null : User::query()->where('role', 'admin')->orderBy('id')->first();
            if ($accounts->isNotEmpty() && $actor === null) {
                throw new RuntimeException('An Admin account is required to record Dealer Tier migration history.');
            }

            PriceList::query()->where('dealer_tier_id', $legacy->id)->update(['dealer_tier_id' => $preset['SILVER']->id]);
            foreach ($accounts as $account) {
                $tierService->change($account, $preset['SILVER'], 'Replace demo initial Tier with fixed Silver preset', (string) Str::uuid(), $actor);
            }
            $legacy->update(['status' => 'inactive']);
        }, 3);
    }
}
