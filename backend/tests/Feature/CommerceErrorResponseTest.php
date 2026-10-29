<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\CommerceErrorResponder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommerceErrorResponseTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_retail_cart_validation_explains_quantity_without_laravel_summary(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->postJson('/api/retail/cart/items', ['quantity' => '1.5'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('message', 'Vui lòng nhập hoặc chọn SKU.')
            ->assertJsonPath('errors.product_variant_id.0', 'Vui lòng nhập hoặc chọn SKU.')
            ->assertJsonPath('errors.quantity.0', 'Số lượng phải là số nguyên.');
    }

    public function test_empty_checkout_returns_a_vietnamese_business_message_and_keeps_code(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->getJson('/api/retail/checkout/review')
            ->assertConflict()
            ->assertJsonPath('code', 'CART_EMPTY')
            ->assertJsonPath('message', 'Giỏ hàng hiện không có sản phẩm. Vui lòng thêm sản phẩm trước khi đặt hàng.');
    }

    public function test_promotion_validation_names_discount_and_returns_one_field_message(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/sales-promotions', [
            'code' => 'SAVE-101', 'name' => 'Khuyến mãi', 'discount_type' => 'percentage',
            'discount_value' => '101', 'sales_scope' => 'retail', 'status' => 'active',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.discount_value.0', 'Phần trăm giảm phải lớn hơn 0% và không vượt quá 100%.');
    }

    public function test_product_required_name_uses_product_language(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/products', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Vui lòng nhập tên sản phẩm.');
    }

    public function test_variant_requires_a_sku_with_a_clear_field_message(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();

        $this->postJson("/api/admin/products/{$product->id}/variants", [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.sku.0', 'Vui lòng nhập SKU.');
    }

    public function test_clinic_paths_are_excluded_from_commerce_error_rendering(): void
    {
        $responder = app(CommerceErrorResponder::class);

        $this->assertFalse($responder->appliesTo(Request::create('/api/appointments', 'POST')));
        $this->assertFalse($responder->appliesTo(Request::create('/api/admin/doctors', 'POST')));
        $this->assertFalse($responder->appliesTo(Request::create('/api/admin/reports/clinic-summary', 'GET')));
        $this->assertTrue($responder->appliesTo(Request::create('/api/admin/sales-orders', 'POST')));
    }

    public function test_unauthenticated_commerce_request_has_a_clear_401_message(): void
    {
        $this->getJson('/api/retail/cart')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.');
    }

    public function test_commerce_server_error_does_not_expose_database_exception_text(): void
    {
        $response = app(CommerceErrorResponder::class)->respond(
            response()->json(['message' => 'SQLSTATE[42S22]: Unknown column products.secret', 'trace' => ['private-path']], 500),
            null,
            Request::create('/api/products', 'GET'),
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['message' => 'Hệ thống đang gặp sự cố. Vui lòng thử lại sau.'], $response->getData(true));
    }
}
