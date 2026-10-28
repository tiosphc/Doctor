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
use Illuminate\Testing\TestResponse;
use PharData;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Tests\TestCase;

class AdminBladeDealerPriceImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_import_requires_admin_and_is_not_available_for_legacy_wizard_draft(): void
    {
        $product = Product::factory()->create();
        $draft = Product::factory()->create(['status' => 'draft', 'wizard_key' => 'legacy-draft']);
        $url = '/admin/prices/dealer/products/'.$product->id.'/import';

        $this->get($url)->assertRedirect('/login');
        $this->post($url.'/preview', ['source' => 'paste', 'paste' => 'GOLD SKU 1 100'])->assertRedirect('/login');
        $this->post($url.'/apply')->assertRedirect('/login');
        $this->actingAs(User::factory()->customer()->create());
        $this->get($url)->assertForbidden();
        $this->post($url.'/apply')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create());
        $this->withoutVite();
        $this->get('/admin/prices/dealer/products/'.$draft->id.'/import')->assertNotFound();
        $this->get($url)->assertOk();
        $this->post($url.'/apply')->assertSessionHasErrors('import');
    }

    public function test_paste_preview_blocks_all_rows_until_errors_are_fixed_then_applies_atomically(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create();
        $first = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-A', 'sellable_dealer' => true]);
        $second = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-B', 'sellable_dealer' => true]);
        $tier = DealerTier::factory()->create(['code' => 'WHOLESALE', 'name' => 'Wholesale']);
        $url = '/admin/prices/dealer/products/'.$product->id.'/import';
        $this->actingAs(User::factory()->admin()->create());

        $this->post($url.'/preview', ['source' => 'paste', 'paste' => "WHOLESALE IMPORT-A 5 100.25\nWHOLESALE MISSING 1 90"])
            ->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('SKU không thuộc sản phẩm này.');
        $this->post($url.'/apply')->assertSessionHasErrors('import');
        $this->assertDatabaseCount('price_list_items', 0);

        $this->post($url.'/preview', ['source' => 'paste', 'paste' => "WHOLESALE IMPORT-A 5 100.25\nWHOLESALE IMPORT-B 1 90"])
            ->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('Áp dụng 2 dòng giá');
        $this->post($url.'/apply')->assertRedirect(route('admin.web.prices.dealer.index', ['product_id' => $product->id]))
            ->assertSessionHas('success');
        $this->assertSame('100.25', app(DealerPricingService::class)->resolve($first, $tier, '5')['unit_price']);
        $this->assertSame('90.00', app(DealerPricingService::class)->resolve($second, $tier, '1')['unit_price']);
        $this->assertDatabaseCount('price_list_items', 2);
    }

    public function test_duplicate_tier_sku_and_invalid_values_block_import(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-A']);
        DealerTier::factory()->create(['code' => 'WHOLESALE', 'name' => 'Wholesale']);
        $url = '/admin/prices/dealer/products/'.$product->id.'/import';
        $this->actingAs(User::factory()->admin()->create());

        $this->post($url.'/preview', ['source' => 'paste', 'paste' => "WHOLESALE IMPORT-A 0 100\nWHOLESALE IMPORT-A 1 -5\nUNKNOWN IMPORT-A 1 100"])
            ->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('MOQ phải là số nguyên')->assertSee('Trùng Tier và SKU')
            ->assertSee('Giá phải lớn hơn 0')->assertSee('Tier không tồn tại');
        $this->post($url.'/apply')->assertSessionHasErrors('import');
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_csv_and_xlsx_upload_preview_use_tier_and_sku_columns(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-A', 'sellable_dealer' => true]);
        $tier = DealerTier::factory()->create(['code' => 'WHOLESALE', 'name' => 'Wholesale']);
        $url = '/admin/prices/dealer/products/'.$product->id.'/import';
        $this->actingAs(User::factory()->admin()->create());

        $this->post($url.'/preview', ['source' => 'file', 'file' => UploadedFile::fake()->createWithContent('prices.csv', "Tier,SKU,MOQ,Price\nWHOLESALE,IMPORT-A,2,77.50,\n")])
            ->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('77.50')->assertSee('Áp dụng 1 dòng giá');
        $this->post($url.'/apply')->assertSessionHas('success');
        $this->assertSame('77.50', app(DealerPricingService::class)->resolve($variant, $tier, '2')['unit_price']);

        $path = sys_get_temp_dir().'/dealer-price-test-'.uniqid().'.zip';
        try {
            $archive = new PharData($path);
            $archive->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Prices" r:id="rId1"/></sheets></workbook>');
            $archive->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
            $archive->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Tier</t></is></c><c r="B1" t="inlineStr"><is><t>SKU</t></is></c><c r="C1" t="inlineStr"><is><t>MOQ</t></is></c><c r="D1" t="inlineStr"><is><t>Price</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>WHOLESALE</t></is></c><c r="B2" t="inlineStr"><is><t>IMPORT-A</t></is></c><c r="C2"><v>3</v></c><c r="D2"><v>88.25</v></c></row></sheetData></worksheet>');
            $this->post($url.'/preview', ['source' => 'file', 'file' => new UploadedFile($path, 'prices.xlsx', null, null, true)])
                ->assertRedirect($url);
            $this->get($url)->assertOk()->assertSee('88.25')->assertSee('Áp dụng 1 dòng giá');
            $this->post($url.'/apply')->assertSessionHas('success');
            $this->assertSame('88.25', app(DealerPricingService::class)->resolve($variant, $tier, '3')['unit_price']);
        } finally {
            @unlink($path);
        }
    }

    public function test_import_rolls_back_all_prices_when_a_later_tier_conflicts(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-A']);
        $firstTier = DealerTier::factory()->create(['code' => 'WHOLESALE', 'name' => 'Wholesale']);
        $secondTier = DealerTier::factory()->create(['code' => 'GOLD', 'name' => 'Gold']);
        PriceList::factory()->create(['code' => 'DEALER-TIER-'.$secondTier->id.'-VND']);
        $url = '/admin/prices/dealer/products/'.$product->id.'/import';
        $this->actingAs(User::factory()->admin()->create());

        $this->post($url.'/preview', [
            'source' => 'paste',
            'paste' => "{$firstTier->code} IMPORT-A 1 100\n{$secondTier->code} IMPORT-A 1 90",
        ])->assertRedirect($url);
        $this->post($url.'/apply')->assertSessionHasErrors('import');

        $this->assertDatabaseCount('price_list_items', 0);
        $this->assertDatabaseMissing('price_lists', ['code' => 'DEALER-TIER-'.$firstTier->id.'-VND']);
    }

    public function test_failed_new_preview_clears_previous_import(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-A']);
        DealerTier::factory()->create(['code' => 'WHOLESALE', 'name' => 'Wholesale']);
        $url = '/admin/prices/dealer/products/'.$product->id.'/import';
        $this->actingAs(User::factory()->admin()->create());

        $this->post($url.'/preview', ['source' => 'paste', 'paste' => 'WHOLESALE IMPORT-A 1 100'])
            ->assertRedirect($url);
        $this->post($url.'/preview', ['source' => 'paste', 'paste' => ''])
            ->assertSessionHasErrors('paste');
        $this->post($url.'/apply')->assertSessionHasErrors('import');

        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_xls_import_previews_and_applies_valid_price(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-A', 'sellable_dealer' => true]);
        $tier = DealerTier::factory()->create(['code' => 'WHOLESALE', 'name' => 'Wholesale']);
        $url = '/admin/prices/dealer/products/'.$product->id.'/import';
        $this->actingAs(User::factory()->admin()->create());

        $this->previewXls($url, [['Tier', 'SKU', 'MOQ', 'Price'], ['WHOLESALE', 'IMPORT-A', 2, 77.50]])
            ->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('77.5')->assertSee('Áp dụng 1 dòng giá');
        $this->post($url.'/apply')->assertSessionHas('success');

        $this->assertSame('77.50', app(DealerPricingService::class)->resolve($variant, $tier, '2')['unit_price']);
    }

    public function test_xls_import_shows_invalid_sku_and_tier_errors(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-A']);
        DealerTier::factory()->create(['code' => 'WHOLESALE', 'name' => 'Wholesale']);
        $url = '/admin/prices/dealer/products/'.$product->id.'/import';
        $this->actingAs(User::factory()->admin()->create());

        $this->previewXls($url, [
            ['Tier', 'SKU', 'MOQ', 'Price'],
            ['WHOLESALE', 'MISSING', 1, 100],
            ['UNKNOWN', 'IMPORT-A', 1, 90],
        ])->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('SKU không thuộc sản phẩm này.')
            ->assertSee('Tier không tồn tại hoặc không hoạt động.');
        $this->post($url.'/apply')->assertSessionHasErrors('import');
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_xls_import_shows_invalid_moq_price_and_duplicate_rows(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-A']);
        DealerTier::factory()->create(['code' => 'WHOLESALE', 'name' => 'Wholesale']);
        $url = '/admin/prices/dealer/products/'.$product->id.'/import';
        $this->actingAs(User::factory()->admin()->create());

        $this->previewXls($url, [
            ['Tier', 'SKU', 'MOQ', 'Price'],
            ['WHOLESALE', 'IMPORT-A', 0, 100],
            ['WHOLESALE', 'IMPORT-A', 1, -5],
        ])->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('MOQ phải là số nguyên')
            ->assertSee('Giá phải lớn hơn 0')->assertSee('Trùng Tier và SKU');
        $this->post($url.'/apply')->assertSessionHasErrors('import');
        $this->assertDatabaseCount('price_list_items', 0);
    }

    public function test_xls_import_does_not_write_valid_row_when_later_row_is_invalid(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'IMPORT-A']);
        DealerTier::factory()->create(['code' => 'WHOLESALE', 'name' => 'Wholesale']);
        $url = '/admin/prices/dealer/products/'.$product->id.'/import';
        $this->actingAs(User::factory()->admin()->create());

        $this->previewXls($url, [
            ['Tier', 'SKU', 'MOQ', 'Price'],
            ['WHOLESALE', 'IMPORT-A', 1, 100],
            ['WHOLESALE', 'MISSING', 1, 90],
        ])->assertRedirect($url);
        $this->post($url.'/apply')->assertSessionHasErrors('import');

        $this->assertDatabaseCount('price_list_items', 0);
    }

    /** @param list<list<int|float|string>> $rows */
    private function previewXls(string $url, array $rows): TestResponse
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'dealer-price-xls-');
        try {
            (new Xls($spreadsheet))->save($path);

            return $this->post($url.'/preview', [
                'source' => 'file',
                'file' => new UploadedFile($path, 'prices.xls', null, null, true),
            ]);
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($path);
        }
    }
}
