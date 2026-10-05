<?php

namespace Tests\Feature\Api;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\RetailPricingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

class ProductCatalogDiagnosticControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_404_when_diagnostic_key_is_not_configured(): void
    {
        config()->set('diagnostics.products_key', null);

        $this->withHeader('X-Diagnostic-Key', 'anything')
            ->getJson('/api/internal/diagnostics/products')
            ->assertNotFound();
    }

    public function test_returns_403_when_diagnostic_key_is_incorrect(): void
    {
        config()->set('diagnostics.products_key', 'test-diagnostic-key');

        $this->withHeader('X-Diagnostic-Key', 'wrong-key')
            ->getJson('/api/internal/diagnostics/products')
            ->assertForbidden();
    }

    public function test_valid_key_runs_the_catalog_and_checks_tables_without_returning_product_data(): void
    {
        config()->set('diagnostics.products_key', 'test-diagnostic-key');
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['sellable_retail' => true]);
        PriceList::factory()->create()->items()->create([
            'product_variant_id' => $variant->id,
            'unit_price' => '90000',
            'minimum_quantity' => 1,
        ]);

        $response = $this->withHeader('X-Diagnostic-Key', 'test-diagnostic-key')
            ->getJson('/api/internal/diagnostics/products');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('stage', 'resource')
            ->assertJsonPath('product_count', 1)
            ->assertJsonPath('table_checks.products', true)
            ->assertJsonPath('table_checks.product_variants', true)
            ->assertJsonPath('table_checks.product_categories', true)
            ->assertJsonPath('table_checks.brands', true)
            ->assertJsonPath('table_checks.price_lists', true)
            ->assertJsonPath('table_checks.price_list_items', true)
            ->assertJsonPath('table_checks.sales_promotions', true)
            ->assertJsonPath('table_checks.sales_promotion_targets', true)
            ->assertJsonPath('extensions.bcmath', true)
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertDontSee('test-diagnostic-key')
            ->assertDontSee($product->name);

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('price_list_items', 1);
    }

    public function test_reports_pricing_exception_without_exposing_catalog_or_secret(): void
    {
        config()->set('diagnostics.products_key', 'test-diagnostic-key');
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create(['sellable_retail' => true]);
        PriceList::factory()->create()->items()->create([
            'product_variant_id' => $variant->id,
            'unit_price' => '90000',
            'minimum_quantity' => 1,
        ]);
        $this->mock(RetailPricingService::class)
            ->shouldReceive('resolve')
            ->once()
            ->andThrow(new RuntimeException('DB_PASSWORD=should-not-leak'));
        Exceptions::fake();

        $this->withHeader('X-Diagnostic-Key', 'test-diagnostic-key')
            ->getJson('/api/internal/diagnostics/products')
            ->assertInternalServerError()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('stage', 'pricing')
            ->assertJsonPath('exception_class', RuntimeException::class)
            ->assertJsonPath('message', 'Product catalog execution failed.')
            ->assertJsonPath('sql_state', null)
            ->assertDontSee('test-diagnostic-key')
            ->assertDontSee('should-not-leak')
            ->assertDontSee($product->name);

        Exceptions::assertReported(RuntimeException::class);
    }
}
