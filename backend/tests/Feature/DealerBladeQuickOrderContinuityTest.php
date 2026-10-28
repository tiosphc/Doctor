<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\CustomerPurchaseController;
use App\Models\AdministrativeWard;
use App\Models\Customer;
use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
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
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealerBladeQuickOrderContinuityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{User, DealerAccount, DealerTier, ProductVariant, PriceListItem} */
    private function fixture(): array
    {
        $user = User::factory()->customer()->create(['password' => 'secret-password']);
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['user_id' => $user->id, 'dealer_account_id' => $account->id]);
        $variant = ProductVariant::factory()->create(['track_inventory' => true, 'sellable_dealer' => true]);
        $list = PriceList::factory()->create([
            'pricing_context' => 'dealer', 'scope_type' => 'tier', 'dealer_tier_id' => $tier->id,
        ]);
        $price = PriceListItem::factory()->create([
            'price_list_id' => $list->id, 'product_variant_id' => $variant->id,
            'unit_price' => '210.00', 'minimum_quantity' => '3',
        ]);

        return [$user, $account, $tier, $variant, $price];
    }

    public function test_detail_distinguishes_orderable_and_untracked_priced_skus(): void
    {
        [$user, $account, , $tracked, $price] = $this->fixture();
        $untracked = ProductVariant::factory()->for($tracked->product)->create([
            'track_inventory' => false, 'sellable_dealer' => true,
        ]);
        PriceListItem::factory()->create([
            'price_list_id' => $price->price_list_id, 'product_variant_id' => $untracked->id,
            'unit_price' => '180.00', 'minimum_quantity' => '2',
        ]);
        $this->actingAs($user);

        $detail = $this->get(route('dealer.web.products.show', $tracked->product->slug));
        $detail->assertOk()->assertSee($tracked->sku)->assertSee($untracked->sku)
            ->assertSee(route('dealer.web.quick.create', ['variant_id' => $tracked->id]), false)
            ->assertDontSee(route('dealer.web.quick.create', ['variant_id' => $untracked->id]), false)
            ->assertSee('không theo dõi tồn kho');
        $this->assertSame([$tracked->id], $detail->viewData('orderableVariantIds'));
        $this->getJson("/api/dealer/accounts/{$account->id}/products/{$tracked->product->slug}")
            ->assertOk()->assertJsonCount(2, 'data.variants');
        $this->getJson(route('dealer.web.quick.options', ['search' => substr($tracked->sku, 0, 3)]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tracked->id);
        $this->get(route('dealer.web.quick.create', ['variant_id' => $untracked->id]))
            ->assertOk()->assertViewHas('initialItems', [])->assertSee('không thể đặt qua Đặt hàng nhanh');
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_preselection_uses_primary_account_tier_price_and_moq_without_creating_order(): void
    {
        [$user, $account, , $variant] = $this->fixture();
        $otherTier = DealerTier::factory()->create();
        $otherAccount = DealerAccount::factory()->create(['current_tier_id' => $otherTier->id]);
        DealerAccountUser::factory()->create(['user_id' => $user->id, 'dealer_account_id' => $otherAccount->id]);
        $otherList = PriceList::factory()->create([
            'pricing_context' => 'dealer', 'scope_type' => 'tier', 'dealer_tier_id' => $otherTier->id,
        ]);
        PriceListItem::factory()->create([
            'price_list_id' => $otherList->id, 'product_variant_id' => $variant->id,
            'unit_price' => '50.00', 'minimum_quantity' => '1',
        ]);

        $this->get(route('dealer.web.quick.create', ['variant_id' => $variant->id]))
            ->assertRedirect(route('login'));
        $this->actingAs(User::factory()->customer()->create())
            ->get(route('dealer.web.quick.create', ['variant_id' => $variant->id]))->assertForbidden();
        $response = $this->actingAs($user)->get(route('dealer.web.quick.create', [
            'variant_id' => $variant->id, 'dealer_account_id' => $otherAccount->id,
            'unit_price' => '1', 'tier_id' => $otherTier->id,
        ]));
        $response->assertOk()->assertSee($variant->sku)->assertSee($account->legal_name)
            ->assertViewHas('initialItems', fn (array $items): bool => count($items) === 1
                && $items[0]['product_variant_id'] === $variant->id
                && $items[0]['quantity'] === '3'
                && $items[0]['unit_price'] === '210.00'
                && (float) $items[0]['minimum_quantity'] === 3.0);
        $this->assertNull($response->viewData('preselectionError'));
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_invalid_inactive_and_unpriced_preselection_never_substitutes_another_sku(): void
    {
        [$user, , , $valid] = $this->fixture();
        $unpriced = ProductVariant::factory()->create(['track_inventory' => true, 'sellable_dealer' => true]);
        $inactive = ProductVariant::factory()->create(['track_inventory' => true, 'sellable_dealer' => true, 'status' => 'inactive']);
        $inactiveProduct = ProductVariant::factory()->create(['track_inventory' => true, 'sellable_dealer' => true]);
        $inactiveProduct->product->update(['status' => 'inactive']);
        $this->actingAs($user);

        foreach (['invalid', '0', '999999999', (string) $unpriced->id, (string) $inactive->id,
            (string) $inactiveProduct->id] as $id) {
            $this->get(route('dealer.web.quick.create', ['variant_id' => $id]))
                ->assertOk()->assertViewHas('initialItems', [])
                ->assertSee('không thể đặt qua Đặt hàng nhanh')->assertDontSee($valid->sku);
        }
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_preselected_item_still_uses_server_review_and_idempotent_submit(): void
    {
        [$user, $account, , $variant] = $this->fixture();
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        WarehouseServiceArea::query()->create(['warehouse_id' => $warehouse->id, 'province_code' => '79']);
        $admin = User::factory()->admin()->create();
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => '10', 'operation_key' => (string) Str::uuid(),
        ], $admin->id);
        app(DealerWalletService::class)->recordDeposit($account, [
            'operation_key' => (string) Str::uuid(), 'amount' => '10000.00', 'method' => 'other_manual',
        ], $admin);
        $ward = AdministrativeWard::query()->where('province_code', '79')->firstOrFail();
        $create = $this->actingAs($user)->get(route('dealer.web.quick.create', ['variant_id' => $variant->id]));
        $create->assertViewHas('initialItems', fn (array $items): bool => ($items[0]['quantity'] ?? null) === '3');
        $this->assertNull($create->viewData('preselectionError'));
        $body = [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '3']],
            'recipient_name' => 'Receiving Manager', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '1 Main Street', 'shipping_province_code' => '79',
            'shipping_ward_code' => $ward->code, 'operation_key' => (string) Str::uuid(),
        ];
        $this->post(route('dealer.web.quick.review'), [...$body, 'items' => [[
            'product_variant_id' => $variant->id, 'quantity' => '2',
        ]]])->assertRedirect(route('dealer.web.quick.create'));
        $this->assertFalse(session('review.can_submit'));
        $this->assertContains('DEALER_MOQ_NOT_MET', session('review.items.0.errors'));
        $this->post(route('dealer.web.quick.review'), $body)->assertRedirect(route('dealer.web.quick.create'));
        $this->assertTrue(session('review.can_submit'));
        $this->assertSame('630.00', session('review.grand_total'));
        $fingerprint = session('review.review_fingerprint');
        $this->post(route('dealer.web.quick.store'), [...$body, 'review_fingerprint' => $fingerprint,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => '3', 'unit_price' => '1']],
            'dealer_account_id' => 999])->assertSessionHasErrors(['items.0.unit_price', 'dealer_account_id']);
        $this->assertDatabaseCount('sales_orders', 0);
        $this->post(route('dealer.web.quick.store'), [...$body, 'review_fingerprint' => $fingerprint])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales_orders', 1);
        $this->post(route('dealer.web.quick.store'), [...$body, 'review_fingerprint' => $fingerprint])
            ->assertRedirect();
        $this->assertDatabaseCount('sales_orders', 1);
    }

    public function test_login_returns_to_dealer_pages_only_for_active_members(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$user, $account, , $variant] = $this->fixture();
        $credentials = ['email' => $user->email, 'password' => 'secret-password'];
        $targets = [
            route('dealer.web.products.index', [], false),
            route('dealer.web.products.show', $variant->product->slug, false),
            route('dealer.web.quick.create', ['variant_id' => $variant->id], false),
            route('dealer.web.profile.index', [], false),
            route('dealer.web.addresses.index', [], false),
            route('dealer.web.wallet.index', [], false),
            route('dealer.web.promotions.index', [], false),
            route('retail.web.profile.show', [], false),
        ];
        foreach ($targets as $target) {
            $this->get($target)->assertRedirect(route('login'));
            $this->post(route('blade.login'), $credentials)->assertRedirect($target);
            $this->post(route('blade.logout'))->assertRedirect(route('login'));
        }
        $this->post(route('blade.login'), $credentials)->assertRedirect(route('dealer.web.orders.index'));
        $this->post(route('blade.logout'));
        $this->post(route('blade.login'), [...$credentials, 'return_to' => '/dealer/apply'])
            ->assertRedirect('/dealer/apply');
        $this->post(route('blade.logout'));
        $account->update(['status' => 'suspended']);
        $this->post(route('blade.login'), [...$credentials, 'return_to' => '/dealer/products'])
            ->assertRedirect(route('retail.web.shop.index'));
        $this->get('/dealer/products')->assertForbidden();
    }

    public function test_login_rejects_unsafe_targets_and_keeps_customer_and_admin_defaults(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$user, , , $variant] = $this->fixture();
        $credentials = ['email' => $user->email, 'password' => 'secret-password'];
        $unsafe = [
            'https://evil.example/dealer/products', '//evil.example/dealer/products',
            'javascript:/dealer/products', '/%2Fdealer/products', '/dealer/%2Fproducts',
            '/dealer/quick-order?variant_id=%31', '/admin/customers',
        ];
        foreach ($unsafe as $target) {
            $this->post(route('blade.login'), [...$credentials, 'return_to' => $target])
                ->assertRedirect(route('dealer.web.orders.index'));
            $this->post(route('blade.logout'));
        }
        $this->post(route('blade.login'), [...$credentials,
            'return_to' => route('dealer.web.quick.create', ['variant_id' => $variant->id]),
        ])->assertRedirect(route('dealer.web.quick.create', ['variant_id' => $variant->id], false));
        $this->post(route('blade.logout'));
        $customer = User::factory()->customer()->create(['password' => 'secret-password']);
        $this->post(route('blade.login'), ['email' => $customer->email, 'password' => 'secret-password',
            'return_to' => '/dealer/products'])->assertRedirect(route('retail.web.shop.index'));
        $this->post(route('blade.logout'));
        $this->post(route('blade.login'), ['email' => $customer->email, 'password' => 'secret-password',
            'return_to' => '/dealer/application'])->assertRedirect('/dealer/application');
        $this->post(route('blade.logout'));
        $admin = User::factory()->admin()->create(['password' => 'secret-password']);
        $this->post(route('blade.login'), ['email' => $admin->email, 'password' => 'secret-password',
            'return_to' => '/dealer/products'])->assertRedirect(route('admin.web.dashboard'));
    }

    public function test_admin_customer_purchase_routes_have_unique_names_and_keep_contract(): void
    {
        $expected = [
            'admin.customers.purchases.show' => ['purchases', 'show'],
            'admin.customers.purchased-products.index' => ['purchased-products', 'products'],
            'admin.customers.vouchers.index' => ['vouchers', 'vouchers'],
        ];
        $routes = collect(Route::getRoutes()->getRoutes());
        foreach ($expected as $name => [$segment, $action]) {
            $matching = $routes->filter(fn ($route): bool => $route->getName() === $name);
            $this->assertCount(1, $matching);
            $route = $matching->first();
            $this->assertSame('api/admin/customers/{customer}/'.$segment, $route->uri());
            $this->assertContains('GET', $route->methods());
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('admin', $route->gatherMiddleware());
            $this->assertSame(CustomerPurchaseController::class.'@'.$action, $route->getActionName());
            $this->assertSame(url('/api/admin/customers/123/'.$segment), route($name, ['customer' => 123]));
        }
        $this->assertCount(0, $routes->filter(fn ($route): bool => $route->getName() === 'admin.'));
        $customer = Customer::factory()->withUser(User::factory()->customer()->create())->create();
        Sanctum::actingAs(User::factory()->admin()->create());
        foreach ($expected as $name => $_) {
            $this->getJson(route($name, ['customer' => $customer->id]))->assertOk();
        }
        Sanctum::actingAs(User::factory()->customer()->create());
        foreach ($expected as $name => $_) {
            $this->getJson(route($name, ['customer' => $customer->id]))->assertForbidden();
        }
    }
}
