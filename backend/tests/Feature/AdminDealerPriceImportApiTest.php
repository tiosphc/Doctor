<?php

namespace Tests\Feature;

use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\DealerPricingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AdminDealerPriceImportApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_import_requires_admin_and_a_valid_preview(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-A']);
        $tier = DealerTier::factory()->create(['code' => 'WHOLESALE']);
        $base = '/api/admin/dealer-prices/products/'.$product->id.'/import';
        $payload = ['source' => 'paste', 'paste' => $tier->code.' IMPORT-A 1 100'];

        $this->postJson($base.'/preview', $payload)->assertUnauthorized();
        $this->postJson($base.'/apply')->assertUnauthorized();
        $this->actingAs(User::factory()->customer()->create());
        $this->postJson($base.'/preview', $payload)->assertForbidden();
        $this->postJson($base.'/apply')->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->postJson($base.'/apply')->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->get($base.'/template')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_preview_blocks_invalid_rows_and_applies_valid_prices_atomically(): void
    {
        $product = Product::factory()->create();
        $first = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-A', 'sellable_dealer' => true]);
        $second = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-B', 'sellable_dealer' => true]);
        $tier = DealerTier::factory()->create(['code' => 'WHOLESALE']);
        $base = '/api/admin/dealer-prices/products/'.$product->id.'/import';
        $this->actingAs(User::factory()->admin()->create());

        $this->postJson($base.'/preview', [
            'source' => 'paste',
            'paste' => "{$tier->code} IMPORT-A 1 100\n{$tier->code} MISSING 1 90",
        ])->assertOk()->assertJsonPath('can_apply', false);
        $this->postJson($base.'/apply')->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertDatabaseCount('price_list_items', 0);

        $preview = $this->postJson($base.'/preview', [
            'source' => 'paste',
            'paste' => "{$tier->code} IMPORT-A 5 100.25\n{$tier->code} IMPORT-B 1 90",
        ])->assertOk()->assertJsonPath('can_apply', true)->assertJsonCount(2, 'rows');
        $token = $preview->json('preview_token');
        $this->postJson($base.'/apply', ['preview_token' => $token])->assertOk()->assertJsonPath('applied_count', 2);
        $this->assertSame('100.25', app(DealerPricingService::class)->resolve($first, $tier, '5')['unit_price']);
        $this->assertSame('90.00', app(DealerPricingService::class)->resolve($second, $tier, '1')['unit_price']);
        $this->postJson($base.'/apply', ['preview_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
    }

    public function test_file_preview_token_is_bound_to_admin_and_expires(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-A']);
        $tier = DealerTier::factory()->create(['code' => 'WHOLESALE']);
        $base = '/api/admin/dealer-prices/products/'.$product->id.'/import';
        $firstAdmin = User::factory()->admin()->create();
        $this->actingAs($firstAdmin);

        $preview = $this->post($base.'/preview', [
            'source' => 'file',
            'file' => UploadedFile::fake()->createWithContent('prices.csv', "Tier,SKU,MOQ,Price\n{$tier->code},IMPORT-A,1,100\n"),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('can_apply', true);
        $token = $preview->json('preview_token');

        $this->actingAs(User::factory()->admin()->create());
        $this->postJson($base.'/apply', ['preview_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('preview_token');

        $this->actingAs($firstAdmin);
        $this->travel(11)->minutes();
        $this->postJson($base.'/apply', ['preview_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_csv_xls_and_xlsx_files_preview_and_apply_prices(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'sku' => 'IMPORT-A', 'sellable_dealer' => true,
        ]);
        $tier = DealerTier::factory()->create(['code' => 'WHOLESALE']);
        $base = '/api/admin/dealer-prices/products/'.$product->id.'/import';
        $this->actingAs(User::factory()->admin()->create());

        $csv = $this->post($base.'/preview', [
            'source' => 'file',
            'file' => UploadedFile::fake()->createWithContent(
                'prices.csv', "Tier,SKU,MOQ,Price\n{$tier->code},IMPORT-A,2,77.50\n",
            ),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('can_apply', true);
        $this->postJson($base.'/apply', ['preview_token' => $csv->json('preview_token')])
            ->assertOk()->assertJsonPath('applied_count', 1);
        $this->assertSame('77.50', app(DealerPricingService::class)->resolve($variant, $tier, '2')['unit_price']);

        foreach (['xls' => Xls::class, 'xlsx' => Xlsx::class] as $extension => $writerClass) {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->fromArray([
                ['Tier', 'SKU', 'MOQ', 'Price'],
                [$tier->code, 'IMPORT-A', 3, 88.25],
            ]);
            $path = tempnam(sys_get_temp_dir(), 'dealer-price-import-');
            try {
                (new $writerClass($spreadsheet))->save($path);
                $preview = $this->post($base.'/preview', [
                    'source' => 'file',
                    'file' => new UploadedFile($path, 'prices.'.$extension, null, null, true),
                ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('can_apply', true);
                $this->postJson($base.'/apply', ['preview_token' => $preview->json('preview_token')])
                    ->assertOk()->assertJsonPath('applied_count', 1);
                $this->assertSame('88.25', app(DealerPricingService::class)->resolve($variant, $tier, '3')['unit_price']);
            } finally {
                $spreadsheet->disconnectWorksheets();
                @unlink($path);
            }
        }
    }

    public function test_conflicting_price_list_rolls_back_every_import_row(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-A']);
        $firstTier = DealerTier::factory()->create(['code' => 'WHOLESALE']);
        $secondTier = DealerTier::factory()->create(['code' => 'GOLD']);
        PriceList::factory()->create(['code' => 'DEALER-TIER-'.$secondTier->id.'-VND']);
        $base = '/api/admin/dealer-prices/products/'.$product->id.'/import';
        $this->actingAs(User::factory()->admin()->create());

        $preview = $this->postJson($base.'/preview', [
            'source' => 'paste',
            'paste' => "{$firstTier->code} IMPORT-A 1 100\n{$secondTier->code} IMPORT-A 1 90",
        ])->assertOk()->assertJsonPath('can_apply', true);
        $this->postJson($base.'/apply', ['preview_token' => $preview->json('preview_token')])
            ->assertStatus(409);

        $this->assertDatabaseCount('price_list_items', 0);
        $this->assertDatabaseMissing('price_lists', ['code' => 'DEALER-TIER-'.$firstTier->id.'-VND']);
    }
}
