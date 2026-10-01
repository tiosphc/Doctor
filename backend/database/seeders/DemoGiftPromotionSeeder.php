<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesPromotion;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class DemoGiftPromotionSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo gift seeding is restricted to local and testing.');
        }

        $admin = User::query()->where('email', 'admin@demo.local')->firstOrFail();
        $warehouse = Warehouse::query()->where('status', 'active')->where('is_default_sales', true)->firstOrFail();
        $inventory = app(InventoryService::class);

        foreach ([
            ['DEMO-PRD-01', 'DEMO-GIFT-01', 'Mini gentle cleanser', 'DEMOGIFT-CLEAN', 'Mua Hydrating serum, tặng Mini gentle cleanser', '1', '1', 60],
            ['DEMO-PRD-02', 'DEMO-GIFT-02', 'Soothing sheet mask', 'DEMOGIFT-MASK', 'Mua Barrier cream, tặng Soothing sheet mask', '2', '1', 50],
            ['DEMO-PRD-04', 'DEMO-GIFT-03', 'Aftercare travel kit', 'DEMOGIFT-CARE', 'Mua Skin booster kit, tặng Aftercare travel kit', '1', '1', 40],
        ] as [$buyCode, $giftCode, $giftName, $promotionCode, $promotionName, $minimumBuy, $giftQuantity, $openingStock]) {
            $buyProduct = Product::query()->where('product_code', $buyCode)->firstOrFail();
            $giftProduct = Product::query()->firstOrCreate(['product_code' => $giftCode], [
                'name' => $giftName,
                'slug' => strtolower($giftCode),
                'description' => 'Demo gift item',
                'product_category_id' => $buyProduct->product_category_id,
                'status' => 'active',
                'track_inventory' => true,
                'can_be_gift' => true,
                'gift_only' => true,
            ]);
            if (! $giftProduct->can_be_gift || ! $giftProduct->gift_only || ! $giftProduct->track_inventory) {
                throw new RuntimeException('Existing demo gift Product has incompatible flags: '.$giftCode);
            }

            $variant = ProductVariant::query()->firstOrCreate(['sku' => $giftCode.'-1'], [
                'product_id' => $giftProduct->id,
                'variant_name' => 'Gift',
                'unit_id' => Unit::query()->where('code', ProductUnitSeeder::unitCodeForDemoProduct($giftCode))->firstOrFail()->id,
                'status' => 'active',
                'sellable_retail' => false,
                'sellable_dealer' => false,
                'track_inventory' => true,
            ]);
            if ($variant->product_id !== $giftProduct->id || ! $variant->track_inventory
                || $variant->sellable_retail || $variant->sellable_dealer) {
                throw new RuntimeException('Existing demo gift SKU is incompatible: '.$variant->sku);
            }

            $promotion = SalesPromotion::query()->firstOrCreate(['normalized_code' => $promotionCode], [
                'code' => $promotionCode,
                'name' => $promotionName,
                'discount_type' => 'buy_a_get_b',
                'discount_value' => '0.00',
                'minimum_order_amount' => '0.00',
                'sales_scope' => 'both',
                'status' => 'active',
                'created_by_user_id' => $admin->id,
            ]);
            if ($promotion->discount_type !== 'buy_a_get_b') {
                throw new RuntimeException('Existing demo promotion code is incompatible: '.$promotionCode);
            }
            $promotion->giftRule()->firstOrCreate([], [
                'buy_product_id' => $buyProduct->id,
                'buy_variant_id' => null,
                'minimum_buy_quantity' => $minimumBuy,
                'gift_product_id' => $giftProduct->id,
                'gift_variant_id' => $variant->id,
                'gift_quantity' => $giftQuantity,
                'repeat_per_multiple' => false,
            ]);

            if (! DB::table('stock_movements')->where('warehouse_id', $warehouse->id)
                ->where('product_variant_id', $variant->id)->exists()) {
                $inventory->opening([
                    'warehouse_id' => $warehouse->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => (string) $openingStock,
                    'operation_key' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'junie-demo-gift-'.$variant->sku.'-'.$warehouse->code),
                    'reason_detail' => 'Demo gift opening stock',
                ], $admin->id);
            }
        }
    }
}
