<?php

namespace Tests\Feature;

use App\Models\AdministrativeProvince;
use App\Models\AdministrativeWard;
use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerShippingAddress;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseServiceArea;
use App\Services\DealerWalletService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealerWarehouseAllocationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{User, DealerAccount, ProductVariant, Warehouse, Warehouse, DealerShippingAddress, DealerShippingAddress} */
    private function fixture(string $hcmStock = '10', string $hanoiStock = '10'): array
    {
        $user = User::factory()->customer()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_variant_id' => $variant->id,
            'unit_price' => '730000.00', 'minimum_quantity' => '1']);
        $hcm = Warehouse::factory()->create(['dealer_price_adjustment_percent' => '0.00', 'is_default_sales' => true]);
        $hanoi = Warehouse::factory()->create(['dealer_price_adjustment_percent' => '3.00']);
        WarehouseServiceArea::query()->create(['warehouse_id' => $hcm->id, 'province_code' => '79', 'priority' => 1]);
        WarehouseServiceArea::query()->create(['warehouse_id' => $hanoi->id, 'province_code' => '1', 'priority' => 1]);
        $wardHcm = AdministrativeWard::query()->where('province_code', '79')->firstOrFail();
        $wardHanoi = AdministrativeWard::query()->where('province_code', '1')->firstOrFail();
        $addressHcm = DealerShippingAddress::query()->create(['dealer_account_id' => $account->id,
            'recipient_name' => 'Người nhận HCM', 'recipient_phone' => '0900000001', 'address_line' => '1 Main Street',
            'province_code' => '79', 'ward_code' => $wardHcm->code, 'is_default' => true]);
        $addressHanoi = DealerShippingAddress::query()->create(['dealer_account_id' => $account->id,
            'recipient_name' => 'Người nhận Hà Nội', 'recipient_phone' => '0900000002', 'address_line' => '2 Main Street',
            'province_code' => '1', 'ward_code' => $wardHanoi->code]);
        $admin = User::factory()->admin()->create();
        app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(),
            'amount' => '10000000.00', 'method' => 'other_manual'], $admin);
        foreach ([[$hcm, $hcmStock], [$hanoi, $hanoiStock]] as [$warehouse, $stock]) {
            if ($stock !== '0') {
                app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
                    'product_variant_id' => $variant->id, 'quantity' => $stock,
                    'operation_key' => (string) Str::uuid()], $admin->id);
            }
        }

        return [$user, $account, $variant, $hcm, $hanoi, $addressHcm, $addressHanoi];
    }

    public function test_address_selects_warehouse_without_changing_dealer_price(): void
    {
        [$user, $account, $variant, $hcm, $hanoi, $addressHcm, $addressHanoi] = $this->fixture();
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/quick-order/review";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '1']];

        $this->postJson($url, ['items' => $items, 'shipping_address_id' => $addressHcm->id])
            ->assertOk()->assertJsonPath('data.warehouse.id', $hcm->id)
            ->assertJsonPath('data.items.0.unit_price', '730000.00');
        $this->postJson($url, ['items' => $items, 'shipping_address_id' => $addressHanoi->id])
            ->assertOk()->assertJsonPath('data.warehouse.id', $hanoi->id)
            ->assertJsonPath('data.items.0.unit_price', '730000.00');
    }

    public function test_fallback_uses_actual_warehouse_and_keeps_tier_price_snapshot(): void
    {
        [$user, $account, $variant, $hcm, $hanoi, , $addressHanoi] = $this->fixture('10', '0');
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/quick-order";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '1']];

        $review = $this->postJson("$url/review", ['items' => $items, 'shipping_address_id' => $addressHanoi->id])
            ->assertOk()->assertJsonPath('data.preferred_warehouse.id', $hanoi->id)
            ->assertJsonPath('data.warehouse.id', $hcm->id)->assertJsonPath('data.fallback_used', true)
            ->assertJsonPath('data.items.0.unit_price', '730000.00')->json('data');
        $order = $this->postJson($url, ['items' => $items, 'shipping_address_id' => $addressHanoi->id,
            'operation_key' => (string) Str::uuid(), 'review_fingerprint' => $review['review_fingerprint']])
            ->assertCreated()->json('data');

        $this->assertDatabaseHas('sales_orders', ['id' => $order['id'], 'warehouse_id' => $hcm->id,
            'shipping_province_code' => '1', 'shipping_ward_code' => $addressHanoi->ward_code]);
        $this->assertDatabaseHas('sales_order_items', ['sales_order_id' => $order['id'], 'unit_price_snapshot' => '730000.00']);
    }

    public function test_foreign_address_and_spoofed_warehouse_are_rejected(): void
    {
        [$user, $account, $variant, $hcm, , , $addressHanoi] = $this->fixture();
        $other = DealerAccount::factory()->create();
        $addressHanoi->update(['dealer_account_id' => $other->id]);
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/quick-order/review";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '1']];

        $this->postJson($url, ['items' => $items, 'shipping_address_id' => $addressHanoi->id])->assertNotFound();
        $this->postJson($url, ['items' => $items, 'shipping_province_code' => '79',
            'shipping_ward_code' => AdministrativeWard::query()->where('province_code', '79')->value('code'),
            'warehouse_id' => $hcm->id])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
    }

    public function test_hcm_falls_back_to_hanoi_and_blocks_when_both_warehouses_lack_stock(): void
    {
        [$user, $account, $variant, $hcm, $hanoi, $addressHcm] = $this->fixture('0', '10');
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/quick-order/review";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '1']];

        $this->postJson($url, ['items' => $items, 'shipping_address_id' => $addressHcm->id])
            ->assertOk()->assertJsonPath('data.preferred_warehouse.id', $hcm->id)
            ->assertJsonPath('data.warehouse.id', $hanoi->id)
            ->assertJsonPath('data.fallback_used', true)
            ->assertJsonPath('data.items.0.unit_price', '730000.00');
        $this->postJson($url, ['items' => [['product_variant_id' => $variant->id, 'quantity' => '11']],
            'shipping_address_id' => $addressHcm->id])->assertOk()
            ->assertJsonPath('data.warehouse_sufficient', false)
            ->assertJsonPath('data.can_submit', false);
    }

    public function test_unmapped_province_uses_default_sales_warehouse_and_wrong_ward_is_rejected(): void
    {
        [$user, $account, $variant, $hcm, , $addressHcm, $addressHanoi] = $this->fixture();
        Sanctum::actingAs($user);
        $base = "/api/dealer/accounts/{$account->id}";
        $province = AdministrativeProvince::query()->where('name', 'Tỉnh Vĩnh Long')->firstOrFail();
        $ward = AdministrativeWard::query()->where('province_code', $province->code)->firstOrFail();

        $this->postJson("$base/shipping-addresses", ['recipient_name' => 'Sai địa chỉ',
            'recipient_phone' => '0900000003', 'address_line' => '3 Main Street',
            'province_code' => $addressHcm->province_code, 'ward_code' => $addressHanoi->ward_code])
            ->assertUnprocessable()->assertJsonValidationErrors('ward_code');
        $input = ['items' => [['product_variant_id' => $variant->id, 'quantity' => '1']],
            'recipient_name' => 'Khách', 'recipient_phone' => '0900000004', 'shipping_address_line1' => '4 Street',
            'shipping_province_code' => $province->code, 'shipping_ward_code' => $ward->code];
        $review = $this->postJson("$base/quick-order/review", $input)
            ->assertOk()->assertJsonPath('data.preferred_warehouse.id', $hcm->id)
            ->assertJsonPath('data.warehouse.id', $hcm->id)
            ->assertJsonPath('data.default_area_used', true)
            ->assertJsonPath('data.warehouse_sufficient', true)->json('data');
        $order = $this->postJson("$base/quick-order", [...$input,
            'operation_key' => (string) Str::uuid(), 'review_fingerprint' => $review['review_fingerprint'],
        ])->assertCreated()->json('data');
        $this->assertDatabaseHas('sales_orders', ['id' => $order['id'], 'warehouse_id' => $hcm->id,
            'shipping_province_code' => $province->code, 'shipping_ward_code' => $ward->code]);
    }

    public function test_unmapped_province_falls_back_to_another_warehouse_when_default_lacks_stock(): void
    {
        [$user, $account, $variant, $hcm, $hanoi] = $this->fixture('0', '10');
        Sanctum::actingAs($user);
        $province = AdministrativeProvince::query()->where('name', 'Tỉnh Vĩnh Long')->firstOrFail();
        $ward = AdministrativeWard::query()->where('province_code', $province->code)->firstOrFail();

        $this->postJson("/api/dealer/accounts/{$account->id}/quick-order/review", [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '1']],
            'recipient_name' => 'Customer', 'recipient_phone' => '0900000004',
            'shipping_address_line1' => '4 Street', 'shipping_province_code' => $province->code,
            'shipping_ward_code' => $ward->code,
        ])->assertOk()->assertJsonPath('data.preferred_warehouse.id', $hcm->id)
            ->assertJsonPath('data.warehouse.id', $hanoi->id)
            ->assertJsonPath('data.default_area_used', true)
            ->assertJsonPath('data.fallback_used', true);
    }

    public function test_unmapped_province_without_active_default_has_actionable_error(): void
    {
        [$user, $account, $variant, $hcm] = $this->fixture();
        $hcm->update(['is_default_sales' => false]);
        Sanctum::actingAs($user);
        $province = AdministrativeProvince::query()->where('name', 'Tỉnh Vĩnh Long')->firstOrFail();
        $ward = AdministrativeWard::query()->where('province_code', $province->code)->firstOrFail();

        $this->postJson("/api/dealer/accounts/{$account->id}/quick-order/review", [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '1']],
            'recipient_name' => 'Customer', 'recipient_phone' => '0900000004',
            'shipping_address_line1' => '4 Street', 'shipping_province_code' => $province->code,
            'shipping_ward_code' => $ward->code,
        ])->assertConflict()->assertJsonPath('code', 'WAREHOUSE_SERVICE_AREA_NOT_FOUND')
            ->assertJsonPath('message', 'Chưa có kho bán hàng đang hoạt động cho khu vực này. Vui lòng liên hệ quản trị viên hoặc chọn địa chỉ khác.');
    }

    public function test_existing_order_and_new_review_keep_tier_price_after_warehouse_configuration_changes(): void
    {
        [$user, $account, $variant, , $hanoi, , $addressHanoi] = $this->fixture();
        Sanctum::actingAs($user);
        $base = "/api/dealer/accounts/{$account->id}/quick-order";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '1']];
        $input = ['items' => $items, 'shipping_address_id' => $addressHanoi->id];
        $review = $this->postJson("$base/review", $input)->assertOk()->json('data');
        $order = $this->postJson($base, [...$input, 'operation_key' => (string) Str::uuid(),
            'review_fingerprint' => $review['review_fingerprint']])->assertCreated()->json('data');

        $hanoi->update(['dealer_price_adjustment_percent' => '5.00']);

        $this->getJson("/api/dealer/accounts/{$account->id}/orders/{$order['id']}")
            ->assertOk()->assertJsonPath('data.items.0.unit_price', '730000.00');
        $this->postJson("$base/review", $input)->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '730000.00');
    }

    public function test_unsaved_shipping_address_uses_canonical_location_and_creates_order(): void
    {
        [$user, $account, $variant, $hcm] = $this->fixture();
        Sanctum::actingAs($user);
        $ward = AdministrativeWard::query()->where('province_code', '79')->firstOrFail();
        $base = "/api/dealer/accounts/{$account->id}/quick-order";
        $input = [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '1']],
            'recipient_name' => 'New customer', 'recipient_phone' => '0900000010',
            'shipping_address_line1' => '10 New Street',
            'shipping_province_code' => '79', 'shipping_ward_code' => $ward->code,
        ];

        $review = $this->postJson("$base/review", $input)->assertOk()
            ->assertJsonPath('data.warehouse.id', $hcm->id)->json('data');
        $this->postJson($base, [...$review['recipient_defaults'], ...$input,
            'operation_key' => (string) Str::uuid(), 'review_fingerprint' => $review['review_fingerprint'],
            'delivery_note' => 'Please call before delivery',
        ])->assertCreated()->assertJsonPath('data.shipping_province_code', '79')
            ->assertJsonPath('data.shipping_ward_code', $ward->code);
        $this->assertDatabaseCount('dealer_shipping_addresses', 2);
    }

    public function test_address_is_saved_only_when_requested_and_order_keeps_its_snapshot(): void
    {
        [$user, $account, $variant] = $this->fixture();
        Sanctum::actingAs($user);
        $ward = AdministrativeWard::query()->where('province_code', '79')->firstOrFail();
        $base = "/api/dealer/accounts/{$account->id}/quick-order";
        $input = [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '1']],
            'recipient_name' => 'New customer', 'recipient_phone' => '0900000010',
            'shipping_address_line1' => '10 New Street',
            'shipping_province_code' => '79', 'shipping_ward_code' => $ward->code,
        ];
        $review = $this->postJson("$base/review", $input)->assertOk()->json('data');
        $order = $this->postJson($base, [...$input,
            'operation_key' => (string) Str::uuid(), 'review_fingerprint' => $review['review_fingerprint'],
            'save_address' => true])->assertCreated()->json('data');

        $this->assertDatabaseCount('dealer_shipping_addresses', 3);
        $saved = DealerShippingAddress::query()->where('dealer_account_id', $account->id)
            ->where('recipient_phone', '0900000010')->firstOrFail();
        $saved->update(['address_line' => '20 Changed Street']);
        $this->assertDatabaseHas('sales_orders', ['id' => $order['id'],
            'shipping_address_line1' => '10 New Street', 'shipping_ward_code' => $ward->code]);
        $this->getJson("/api/dealer/accounts/{$account->id}/orders/{$order['id']}")
            ->assertOk()->assertJsonPath('data.shipping_address_line1', '10 New Street');
    }

    public function test_quick_order_rejects_a_phone_without_digits_before_review(): void
    {
        [$user, $account, $variant] = $this->fixture();
        Sanctum::actingAs($user);
        $ward = AdministrativeWard::query()->where('province_code', '79')->firstOrFail();

        $this->postJson("/api/dealer/accounts/{$account->id}/quick-order/review", [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '1']],
            'recipient_name' => 'New customer', 'recipient_phone' => 'abc',
            'shipping_address_line1' => '10 New Street',
            'shipping_province_code' => '79', 'shipping_ward_code' => $ward->code,
        ])->assertUnprocessable()->assertJsonValidationErrors('recipient_phone');
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_saving_identical_address_again_does_not_duplicate_and_editing_form_does_not_change_saved_address(): void
    {
        [$user, $account, $variant, , , $saved] = $this->fixture();
        Sanctum::actingAs($user);
        $base = "/api/dealer/accounts/{$account->id}/quick-order";
        $input = [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '1']],
            'recipient_name' => $saved->recipient_name,
            'recipient_phone' => $saved->recipient_phone,
            'shipping_address_line1' => $saved->address_line,
            'shipping_province_code' => $saved->province_code,
            'shipping_ward_code' => $saved->ward_code,
        ];
        $review = $this->postJson("$base/review", $input)->assertOk()->json('data');
        $this->postJson($base, [...$input, 'operation_key' => (string) Str::uuid(),
            'review_fingerprint' => $review['review_fingerprint'], 'save_address' => true])->assertCreated();
        $this->assertDatabaseCount('dealer_shipping_addresses', 2);

        $edited = [...$input, 'shipping_address_line1' => 'Edited in order only'];
        $editedReview = $this->postJson("$base/review", $edited)->assertOk()->json('data');
        $order = $this->postJson($base, [...$edited, 'operation_key' => (string) Str::uuid(),
            'review_fingerprint' => $editedReview['review_fingerprint'], 'save_address' => false])
            ->assertCreated()->json('data');
        $this->assertDatabaseHas('sales_orders', ['id' => $order['id'],
            'shipping_address_line1' => 'Edited in order only']);
        $this->assertSame('1 Main Street', $saved->refresh()->address_line);
        $this->assertDatabaseCount('dealer_shipping_addresses', 2);
    }

    public function test_order_is_not_created_when_no_warehouse_can_fulfill_all_items(): void
    {
        [$user, $account, $variant, , , $addressHcm] = $this->fixture('0', '0');
        Sanctum::actingAs($user);
        $base = "/api/dealer/accounts/{$account->id}/quick-order";
        $input = ['items' => [['product_variant_id' => $variant->id, 'quantity' => '1']],
            'shipping_address_id' => $addressHcm->id];

        $review = $this->postJson("$base/review", $input)->assertOk()
            ->assertJsonPath('data.can_submit', false)->json('data');
        $this->postJson($base, [...$input, 'operation_key' => (string) Str::uuid(),
            'review_fingerprint' => $review['review_fingerprint'], 'save_address' => true])
            ->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK');
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('dealer_shipping_addresses', 2);
    }
}
