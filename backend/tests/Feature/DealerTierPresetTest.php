<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\DealerTierHistory;
use App\Models\PriceList;
use App\Models\SalesPromotion;
use App\Models\User;
use Database\Seeders\DealerTierPresetSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealerTierPresetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_three_presets_are_idempotent_and_only_thresholds_can_be_edited(): void
    {
        $this->seed(DealerTierPresetSeeder::class);
        $this->seed(DealerTierPresetSeeder::class);
        $this->assertDatabaseCount('dealer_tiers', 3);
        $silver = DealerTier::query()->where('code', 'SILVER')->firstOrFail();
        $gold = DealerTier::query()->where('code', 'GOLD')->firstOrFail();
        $diamond = DealerTier::query()->where('code', 'DIAMOND')->firstOrFail();
        $this->assertTrue($silver->is_default_initial);
        $this->assertFalse($gold->is_default_initial);
        $this->assertFalse($diamond->is_default_initial);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->patchJson("/api/admin/dealer-tiers/{$gold->id}", ['revenue_threshold' => '1000000'])
            ->assertOk()->assertJsonPath('data.revenue_threshold', '1000000.00');
        $this->patchJson("/api/admin/dealer-tiers/{$silver->id}", ['revenue_threshold' => '1'])->assertUnprocessable();
        $this->patchJson("/api/admin/dealer-tiers/{$gold->id}", ['revenue_threshold' => '0'])->assertUnprocessable();
        $this->patchJson("/api/admin/dealer-tiers/{$diamond->id}", ['revenue_threshold' => '999999'])->assertUnprocessable();
        $this->patchJson("/api/admin/dealer-tiers/{$gold->id}", ['name' => 'Changed'])->assertUnprocessable();
        $this->patchJson("/api/admin/dealer-tiers/{$gold->id}", ['sort_order' => 9])->assertUnprocessable();
    }

    public function test_legacy_demo_tier_moves_live_account_and_price_list_without_rewriting_history(): void
    {
        $actor = User::factory()->admin()->create();
        $legacy = DealerTier::factory()->create(['code' => 'DEMO-INITIAL', 'name' => 'Demo Initial', 'is_default_initial' => true]);
        $account = DealerAccount::factory()->create(['current_tier_id' => $legacy->id]);
        $initialHistory = DealerTierHistory::factory()->create([
            'dealer_account_id' => $account->id, 'new_tier_id' => $legacy->id,
        ]);
        $priceList = PriceList::factory()->create([
            'code' => 'DEMO-DEALER-DEMO-INITIAL', 'pricing_context' => 'dealer',
            'scope_type' => 'tier', 'dealer_tier_id' => $legacy->id,
        ]);
        $promotion = SalesPromotion::factory()->create();
        DB::table('sales_promotion_dealer_tiers')->insert([
            'sales_promotion_id' => $promotion->id, 'dealer_tier_id' => $legacy->id,
        ]);

        $this->seed(DealerTierPresetSeeder::class);
        $silver = DealerTier::query()->where('code', 'SILVER')->firstOrFail();
        $this->assertSame($silver->id, $account->refresh()->current_tier_id);
        $this->assertSame($silver->id, $priceList->refresh()->dealer_tier_id);
        $this->assertDatabaseHas('sales_promotion_dealer_tiers', [
            'sales_promotion_id' => $promotion->id, 'dealer_tier_id' => $silver->id,
        ]);
        $this->assertSame('inactive', $legacy->refresh()->status);
        $this->assertFalse($legacy->is_default_initial);
        $this->assertDatabaseHas('dealer_tier_histories', [
            'id' => $initialHistory->id, 'new_tier_id' => $legacy->id,
        ]);
        $this->assertDatabaseHas('dealer_tier_histories', [
            'dealer_account_id' => $account->id, 'previous_tier_id' => $legacy->id,
            'new_tier_id' => $silver->id, 'changed_by' => $actor->id,
        ]);
        $this->seed(DealerTierPresetSeeder::class);
        $this->assertDatabaseCount('dealer_tier_histories', 2);
        $this->assertDatabaseCount('dealer_tiers', 4);
    }
}
