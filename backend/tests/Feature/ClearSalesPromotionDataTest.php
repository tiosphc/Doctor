<?php

namespace Tests\Feature;

use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesPromotion;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesPromotionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClearSalesPromotionDataTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_clear_retains_order_gift_item_and_immutable_redemptions_then_allows_new_offer(): void
    {
        $buyer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $warehouse = Warehouse::factory()->create();
        $buyVariant = ProductVariant::factory()->create();
        $giftVariant = ProductVariant::factory()->create();
        $giftVariant->product->update(['can_be_gift' => true, 'gift_only' => true]);
        $discount = SalesPromotion::factory()->create(['code' => 'OLD20', 'normalized_code' => 'OLD20',
            'sales_scope' => 'retail', 'discount_value' => '20.00']);
        $discount->targets()->create(['product_id' => $buyVariant->product_id]);
        $gift = SalesPromotion::factory()->create(['code' => 'OLDGIFT', 'normalized_code' => 'OLDGIFT',
            'discount_type' => 'buy_a_get_b', 'discount_value' => '0.00']);
        $gift->giftRule()->create(['buy_product_id' => $buyVariant->product_id,
            'minimum_buy_quantity' => '1', 'gift_product_id' => $giftVariant->product_id,
            'gift_variant_id' => $giftVariant->id, 'gift_quantity' => '1',
            'repeat_per_multiple' => false]);
        $orderId = DB::table('sales_orders')->insertGetId([
            'order_code' => 'CLEAR-OLD-ORDER', 'creation_operation_key' => (string) Str::uuid(),
            'creation_fingerprint' => str_repeat('a', 64), 'sales_channel' => 'retail',
            'order_source' => 'admin', 'buyer_user_id' => $buyer->id, 'warehouse_id' => $warehouse->id,
            'currency' => 'VND', 'recipient_name' => $buyer->name, 'recipient_phone' => '0900000000',
            'shipping_address_line1' => 'Street', 'shipping_city' => 'HCM',
            'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'order_status' => 'confirmed',
            'price_resolution_fingerprint' => str_repeat('b', 64), 'created_by' => $admin->id,
            'sales_promotion_id' => $discount->id, 'promotion_code_snapshot' => 'OLD20',
            'promotion_name_snapshot' => $discount->name,
            'promotion_discount_type_snapshot' => 'percentage',
            'promotion_discount_value_snapshot' => '20.00',
            'promotion_gift_snapshot' => json_encode(['promotion_id' => $gift->id,
                'gift_variant_id' => $giftVariant->id, 'actual_gift_quantity' => '1']),
            'subtotal' => '100.00', 'discount_total' => '20.00', 'grand_total' => '80.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$buyVariant, false, '100.00', '20.00', '80.00', $discount->id],
            [$giftVariant, true, '0.00', '0.00', '0.00', $gift->id]] as [$variant, $isGift, $base, $saving, $total, $promotionId]) {
            DB::table('sales_order_items')->insert([
                'sales_order_id' => $orderId, 'product_variant_id' => $variant->id,
                'product_code_snapshot' => $variant->product->product_code,
                'product_name_snapshot' => $variant->product->name, 'sku_snapshot' => $variant->sku,
                'variant_name_snapshot' => $variant->variant_name,
                'unit_code_snapshot' => $variant->unit->code,
                'unit_name_snapshot' => $variant->unit->name,
                'quantity' => '1', 'pricing_context_snapshot' => 'retail',
                'price_resolution_fingerprint' => str_repeat('c', 64),
                'unit_price_snapshot' => $base, 'base_amount' => $base,
                'discount_amount' => $saving, 'line_total' => $total,
                'is_gift' => $isGift, 'source_promotion_id' => $isGift ? $promotionId : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ([[$discount, 'percentage', '20.00', '20.00'],
            [$gift, 'buy_a_get_b', '0.00', '0.00']] as [$promotion, $type, $value, $amount]) {
            DB::table('sales_promotion_redemptions')->insert([
                'sales_promotion_id' => $promotion->id, 'sales_order_id' => $orderId,
                'sales_channel' => 'retail', 'buyer_user_id' => $buyer->id,
                'promotion_code_snapshot' => $promotion->code, 'discount_type_snapshot' => $type,
                'discount_value_snapshot' => $value, 'discount_amount' => $amount,
                'status' => 'redeemed', 'redeemed_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('sales-promotions:clear-data')->assertExitCode(0);
        $this->assertDatabaseCount('sales_promotions', 2);
        $this->artisan('sales-promotions:clear-data', ['--apply' => true])->assertExitCode(0);
        $this->artisan('sales-promotions:clear-data', ['--apply' => true])->assertExitCode(0);

        $this->assertDatabaseCount('sales_promotions', 0);
        $this->assertDatabaseCount('sales_promotion_targets', 0);
        $this->assertDatabaseCount('sales_promotion_gift_rules', 0);
        $this->assertDatabaseCount('sales_promotion_redemptions', 2);
        $this->assertDatabaseHas('sales_orders', ['id' => $orderId,
            'sales_promotion_id' => null, 'promotion_code_snapshot' => 'OLD20',
            'discount_total' => '20.00', 'grand_total' => '80.00']);
        $this->assertDatabaseHas('sales_order_items', ['sales_order_id' => $orderId,
            'is_gift' => true, 'source_promotion_id' => null, 'line_total' => '0.00']);
        $this->assertDatabaseHas('sales_promotion_redemptions', ['sales_order_id' => $orderId,
            'sales_promotion_id' => null, 'promotion_code_snapshot' => 'OLD20']);
        $this->artisan('sales-promotions:reconcile', ['--dry-run' => true])->assertExitCode(0);

        Sanctum::actingAs($buyer);
        $this->getJson('/api/retail/orders/'.$orderId)->assertOk()
            ->assertJsonPath('data.promotion.code', 'OLD20')
            ->assertJsonPath('data.promotion.discount_amount', '20.00')
            ->assertJsonPath('data.gift_promotion.code', 'OLDGIFT');
        $order = SalesOrder::query()->findOrFail($orderId);
        $this->assertTrue($order->permitsPromotionGift($giftVariant, null));
        $order->update(['order_status' => 'cancelled']);
        app(SalesPromotionService::class)->release($order);
        $this->assertSame(2, DB::table('sales_promotion_redemptions')->where('status', 'released')->count());
        $this->artisan('sales-promotions:reconcile', ['--dry-run' => true])->assertExitCode(0);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/sales-promotions')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/admin/sales-promotions', ['code' => 'SALE20', 'name' => 'New offer',
            'discount_type' => 'percentage', 'discount_value' => '20', 'sales_scope' => 'retail',
            'status' => 'active', 'product_ids' => [$buyVariant->product_id],
            'category_ids' => []])->assertCreated()->assertJsonPath('data.code', 'SALE20');

        $this->assertDatabaseCount('sales_promotions', 1);
    }
}
