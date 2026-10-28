<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\DealerApplication;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RetailBladeShopProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function pricedVariant(Product $product, PriceList $list, string $sku, string $price = '120000.00'): ProductVariant
    {
        $variant = ProductVariant::factory()->for($product)->create(['sku' => $sku, 'track_inventory' => true]);
        PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_variant_id' => $variant->id, 'unit_price' => $price]);

        return $variant;
    }

    public function test_shop_combines_search_category_brand_and_name_sort(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Selected Category']);
        $brand = Brand::factory()->create(['name' => 'Selected Brand']);
        ProductCategory::factory()->create(['name' => 'Inactive Category', 'status' => 'inactive']);
        Brand::factory()->create(['name' => 'Inactive Brand', 'status' => 'inactive']);
        $list = PriceList::factory()->create();
        $zulu = Product::factory()->create(['name' => 'Target Zulu', 'product_category_id' => $category->id, 'brand_id' => $brand->id]);
        $alpha = Product::factory()->create(['name' => 'Target Alpha', 'product_category_id' => $category->id, 'brand_id' => $brand->id]);
        $wrongBrand = Product::factory()->create(['name' => 'Target Wrong Brand', 'product_category_id' => $category->id]);
        $wrongCategory = Product::factory()->create(['name' => 'Target Wrong Category', 'brand_id' => $brand->id]);
        $this->pricedVariant($zulu, $list, 'SHOP-ZULU');
        $this->pricedVariant($alpha, $list, 'SHOP-ALPHA', '140000.00');
        $this->pricedVariant($alpha, $list, 'SHOP-ALPHA-SECOND', '170000.00');
        $this->pricedVariant($wrongBrand, $list, 'SHOP-WRONG-BRAND');
        $this->pricedVariant($wrongCategory, $list, 'SHOP-WRONG-CATEGORY');

        $response = $this->get(route('retail.web.shop.index', [
            'search' => 'Target', 'category' => $category->id, 'brand' => $brand->id, 'sort' => 'name',
        ]));

        $response->assertOk()->assertSee('3 quy cách phù hợp')->assertSee('SHOP-ALPHA')
            ->assertSee('SHOP-ZULU')->assertDontSee('SHOP-WRONG-BRAND')->assertDontSee('SHOP-WRONG-CATEGORY')
            ->assertSee(route('retail.web.shop.show', $alpha->slug), false)
            ->assertSee('140.000')->assertSee('170.000')->assertDontSee('Giá đại lý')
            ->assertDontSee('Inactive Category')->assertDontSee('Inactive Brand');
        $this->assertLessThan(strpos($response->getContent(), 'SHOP-ZULU'), strpos($response->getContent(), 'SHOP-ALPHA'));
        $response->assertSee('value="'.$category->id.'" selected', false)
            ->assertSee('value="'.$brand->id.'" selected', false);
    }

    public function test_shop_newest_search_and_visibility_follow_retail_variant_contract(): void
    {
        $list = PriceList::factory()->create();
        $older = Product::factory()->create(['name' => 'Older Visible']);
        $newer = Product::factory()->create(['name' => 'Newer Visible']);
        $this->pricedVariant($older, $list, 'SHOP-OLDER');
        $this->pricedVariant($newer, $list, 'SHOP-NEWER');
        $hidden = Product::factory()->create(['name' => 'Hidden Product', 'status' => 'inactive']);
        $this->pricedVariant($hidden, $list, 'SHOP-HIDDEN');
        $inactiveVariant = $this->pricedVariant(Product::factory()->create(), $list, 'SHOP-INACTIVE');
        $inactiveVariant->update(['status' => 'inactive']);
        ProductVariant::factory()->for(Product::factory()->create())->create(['sku' => 'SHOP-UNPRICED', 'track_inventory' => true]);

        $response = $this->get(route('retail.web.shop.index'));
        $response->assertOk()->assertSee('2 quy cách phù hợp')->assertDontSee('SHOP-HIDDEN')
            ->assertDontSee('SHOP-INACTIVE')->assertDontSee('SHOP-UNPRICED');
        $this->assertLessThan(strpos($response->getContent(), 'SHOP-OLDER'), strpos($response->getContent(), 'SHOP-NEWER'));
        $this->get(route('retail.web.shop.index', ['search' => $older->product_code]))
            ->assertOk()->assertSee('SHOP-OLDER')->assertDontSee('SHOP-NEWER');
    }

    public function test_shop_pagination_retains_supported_filters_and_rejects_invalid_inputs(): void
    {
        $category = ProductCategory::factory()->create();
        $brand = Brand::factory()->create();
        $product = Product::factory()->create(['name' => 'Target Batch', 'product_category_id' => $category->id, 'brand_id' => $brand->id]);
        $list = PriceList::factory()->create();
        for ($index = 1; $index <= 21; $index++) {
            $this->pricedVariant($product, $list, sprintf('SHOP-PAGE-%02d', $index));
        }
        $filters = ['search' => 'Target', 'category' => $category->id, 'brand' => $brand->id, 'sort' => 'name'];
        $response = $this->get(route('retail.web.shop.index', $filters + ['tracking' => 'discard']));
        $response->assertOk()->assertSee('21 quy cách phù hợp')->assertSee('SHOP-PAGE-01')->assertDontSee('SHOP-PAGE-21');
        $response->assertViewHas('variants', function ($variants) use ($filters): bool {
            $url = $variants->url(2);

            return $variants->total() === 21 && str_contains($url, 'page=2')
                && collect($filters)->every(fn ($value, $key): bool => str_contains($url, $key.'='.urlencode((string) $value)))
                && ! str_contains($url, 'tracking=');
        });
        $this->get(route('retail.web.shop.index', $filters + ['page' => 2]))
            ->assertOk()->assertSee('SHOP-PAGE-21')->assertDontSee('SHOP-PAGE-01');
        $this->get(route('retail.web.shop.index', ['category' => 99999999]))->assertSessionHasErrors('category');
        $this->get(route('retail.web.shop.index', ['brand' => 'invalid']))->assertSessionHasErrors('brand');
        $this->get(route('retail.web.shop.index', ['sort' => 'price_asc']))->assertSessionHasErrors('sort');
        $this->get(route('retail.web.shop.index', ['page' => '-1']))->assertSessionHasErrors('page');
        $this->get(route('retail.web.shop.index', ['search' => 'no match']))
            ->assertOk()->assertSee('0 quy cách phù hợp')->assertSee('Không có sản phẩm phù hợp.');
    }

    public function test_profile_requires_customer_and_displays_personal_data_and_navigation(): void
    {
        $customer = User::factory()->customer()->create(['name' => 'Profile Owner', 'phone' => '0900000000']);
        $this->get(route('retail.web.profile.show'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->admin()->create())->get(route('retail.web.profile.show'))->assertForbidden();
        $this->actingAs($customer)->get(route('retail.web.profile.show'))
            ->assertOk()->assertSee('Profile Owner')->assertSee($customer->email)
            ->assertSee(route('retail.web.orders.index'), false)
            ->assertSee(route('retail.web.dealer-applications.create'), false)
            ->assertSee('name="name"', false)->assertSee('name="phone"', false)
            ->assertDontSee('name="email"', false);
    }

    public function test_profile_updates_only_owner_and_synchronizes_canonical_customer(): void
    {
        $customer = User::factory()->customer()->create(['name' => 'Original Name', 'phone' => '0900000000']);
        $other = User::factory()->customer()->create(['name' => 'Other Name']);
        $application = DealerApplication::factory()->create(['user_id' => $customer->id, 'contact_name' => 'Original Contact']);
        $originalEmail = $customer->email;

        $this->actingAs($customer)->patch(route('retail.web.profile.update'), [
            'name' => 'Updated Name', 'phone' => '0911111111', 'user_id' => $other->id,
            'customer_id' => $other->id, 'dealer_account_id' => 999,
        ])->assertRedirect(route('retail.web.profile.show'))->assertSessionHas('success');
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => 'Updated Name', 'phone' => '0911111111', 'email' => $originalEmail]);
        $this->assertDatabaseHas('customers', ['user_id' => $customer->id, 'name' => 'Updated Name', 'normalized_phone' => '+84911111111']);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'name' => 'Other Name']);
        $this->assertDatabaseHas('dealer_applications', ['id' => $application->id, 'contact_name' => 'Original Contact']);
    }

    public function test_profile_validation_rejects_unsupported_fields_and_keeps_old_input(): void
    {
        $customer = User::factory()->customer()->create(['name' => 'Original Name']);
        $this->actingAs($customer)->patch(route('retail.web.profile.update'), [
            'name' => '', 'phone' => '0911111111',
        ])->assertSessionHasErrors('name')->assertSessionHasInput('phone', '0911111111');
        foreach (['email' => 'attacker@example.test', 'role' => User::ROLE_ADMIN, 'password' => 'new-password'] as $field => $value) {
            $this->patch(route('retail.web.profile.update'), ['name' => 'Tampered', $field => $value])
                ->assertSessionHasErrors($field);
        }
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => 'Original Name', 'role' => User::ROLE_CUSTOMER]);
    }

    public function test_login_accepts_profile_return_path(): void
    {
        $customer = User::factory()->customer()->create(['password' => bcrypt('secret-password')]);
        $this->post(route('blade.login'), [
            'email' => $customer->email, 'password' => 'secret-password', 'return_to' => '/account/profile',
        ])->assertRedirect('/account/profile');

        $this->get(route('retail.web.profile.show'))->assertOk();
    }
}
