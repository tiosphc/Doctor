<?php

namespace Tests\Feature;

use App\Models\AdministrativeWard;
use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerOrderImport;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseServiceArea;
use App\Services\DealerOrderImportWorkbook;
use App\Services\DealerWalletService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PharData;
use Tests\TestCase;

class DealerBladeSalesTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    /** @return array{User, DealerAccount, ProductVariant, Warehouse, PriceListItem} */
    private function fixture(): array
    {
        $user = User::factory()->customer()->create();
        $tier = DealerTier::factory()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => $tier->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        WarehouseServiceArea::query()->create(['warehouse_id' => $warehouse->id, 'province_code' => '79']);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier',
            'dealer_tier_id' => $tier->id, 'currency' => 'VND']);
        $price = PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '2']);
        $admin = User::factory()->admin()->create();
        app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(),
            'amount' => '100000.00', 'method' => 'other_manual'], $admin);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '10',
            'operation_key' => (string) Str::uuid()], $admin->id);

        return [$user, $account, $variant, $warehouse, $price];
    }

    /** @return array<string, mixed> */
    private function quickData(ProductVariant $variant): array
    {
        $ward = AdministrativeWard::query()->where('province_code', '79')->firstOrFail();

        return ['items' => [['product_variant_id' => $variant->id, 'quantity' => '2']],
            'recipient_name' => 'Receiving Manager', 'recipient_phone' => '0900000000',
            'shipping_address_line1' => '1 Main Street', 'shipping_province_code' => '79',
            'shipping_ward_code' => $ward->code, 'operation_key' => (string) Str::uuid()];
    }

    /** @param list<list<string>> $rows */
    private function workbook(array $rows): string
    {
        $path = sys_get_temp_dir().'/dealer-blade-import-'.Str::uuid().'.zip';
        $this->temporaryFiles[] = $path;
        app(DealerOrderImportWorkbook::class)->template($path);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ([DealerOrderImportWorkbook::HEADERS, ...$rows] as $rowIndex => $values) {
            $xml .= '<row r="'.($rowIndex + 1).'">';
            foreach ($values as $column => $value) {
                $xml .= '<c r="'.chr(65 + $column).($rowIndex + 1).'" t="inlineStr"><is><t>'
                    .htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $xml .= '</row>';
        }
        (new PharData($path))->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');

        return $path;
    }

    public function test_dealer_pages_require_an_active_dealer_membership(): void
    {
        $this->get(route('dealer.web.orders.index'))->assertRedirect();
        $this->actingAs(User::factory()->customer()->create());
        $this->get(route('dealer.web.orders.index'))->assertForbidden();
        $this->get(route('dealer.web.quick.create'))->assertForbidden();
        $this->get(route('dealer.web.imports.index'))->assertForbidden();

        [$user, $account] = $this->fixture();
        $this->actingAs($user);
        $this->get(route('dealer.web.orders.index'))->assertOk();
        $this->get(route('dealer.web.quick.create'))->assertOk();
        $this->get(route('dealer.web.imports.index'))->assertOk();
        $account->update(['status' => 'inactive']);
        $this->get(route('dealer.web.orders.index'))->assertForbidden();
        $account->update(['status' => 'active']);
        DealerAccountUser::query()->where('dealer_account_id', $account->id)
            ->where('user_id', $user->id)->update(['status' => 'inactive']);
        $this->get(route('dealer.web.orders.index'))->assertForbidden();
    }

    public function test_order_list_detail_and_cancel_are_scoped_to_primary_dealer(): void
    {
        [$user, $account, $variant] = $this->fixture();
        $this->actingAs($user);
        $data = $this->quickData($variant);
        $this->post(route('dealer.web.quick.review'), $data)->assertRedirect(route('dealer.web.quick.create'));
        $fingerprint = session('review.review_fingerprint');
        $this->post(route('dealer.web.quick.store'), [...$data, 'review_fingerprint' => $fingerprint])->assertRedirect();
        $order = SalesOrder::query()->firstOrFail();
        $this->get(route('dealer.web.orders.index'))->assertOk()->assertSee($order->order_code);
        $this->get(route('dealer.web.orders.show', $order))->assertOk()->assertSee($order->order_code);
        $stranger = User::factory()->customer()->create();
        $otherAccount = DealerAccount::factory()->create(['current_tier_id' => $account->current_tier_id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $otherAccount->id, 'user_id' => $stranger->id]);
        $this->actingAs($stranger);
        $this->get(route('dealer.web.orders.show', $order))->assertNotFound();
        $this->post(route('dealer.web.orders.cancel', $order), ['operation_key' => (string) Str::uuid()])->assertNotFound();
        $this->actingAs($user);
        $this->post(route('dealer.web.orders.cancel', $order), ['operation_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'order_status' => 'cancelled']);
    }

    public function test_quick_order_review_is_read_only_and_submit_reuses_service_idempotency(): void
    {
        [$user, $account, $variant, $warehouse] = $this->fixture();
        $this->actingAs($user);
        $data = $this->quickData($variant);
        $this->post(route('dealer.web.quick.review'), $data)->assertRedirect(route('dealer.web.quick.create'));
        $review = session('review');
        $this->assertTrue($review['can_submit']);
        $this->assertSame('200.00', $review['grand_total']);
        $this->assertDatabaseCount('sales_orders', 0);
        $this->get(route('dealer.web.quick.options', ['search' => substr($variant->sku, 0, 3)]))
            ->assertOk()->assertJsonPath('data.0.id', $variant->id)->assertJsonPath('data.0.unit_price', '100.00');
        $body = [...$data, 'review_fingerprint' => $review['review_fingerprint']];
        $this->post(route('dealer.web.quick.store'), $body)->assertRedirect();
        $order = SalesOrder::query()->firstOrFail();
        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'dealer_account_id' => $account->id,
            'warehouse_id' => $warehouse->id, 'order_source' => 'quick_order', 'payment_status' => 'paid']);
        $this->assertDatabaseHas('sales_order_items', ['sales_order_id' => $order->id, 'unit_price_snapshot' => '100.00']);
        $this->post(route('dealer.web.quick.store'), $body)->assertRedirect(route('dealer.web.orders.show', $order));
        $this->assertDatabaseCount('sales_orders', 1);
    }

    public function test_quick_order_rejects_stale_price_and_invalid_moq(): void
    {
        [$user, , $variant, , $price] = $this->fixture();
        $this->actingAs($user);
        $data = $this->quickData($variant);
        $this->post(route('dealer.web.quick.review'), [...$data, 'items' => [[
            'product_variant_id' => $variant->id, 'quantity' => '1',
        ]]])->assertRedirect();
        $this->assertFalse(session('review.can_submit'));
        $this->post(route('dealer.web.quick.review'), $data)->assertRedirect();
        $fingerprint = session('review.review_fingerprint');
        $price->update(['unit_price' => '120.00']);
        $this->post(route('dealer.web.quick.store'), [...$data, 'review_fingerprint' => $fingerprint])
            ->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_dealer_list_filters_and_detail_keeps_historical_price(): void
    {
        [$user, , $variant, , $price] = $this->fixture();
        $this->actingAs($user);
        $data = $this->quickData($variant);
        $this->post(route('dealer.web.quick.review'), $data)->assertRedirect();
        $this->post(route('dealer.web.quick.store'), [
            ...$data, 'review_fingerprint' => session('review.review_fingerprint'),
        ])->assertRedirect();
        $order = SalesOrder::query()->firstOrFail();
        $price->update(['unit_price' => '999.00']);

        $this->get(route('dealer.web.orders.index', ['search' => $order->order_code,
            'order_source' => 'quick_order', 'payment_status' => 'paid']))
            ->assertOk()->assertSee($order->order_code);
        $this->get(route('dealer.web.orders.index', ['search' => 'NO-SUCH-ORDER']))
            ->assertOk()->assertDontSee($order->order_code);
        $this->get(route('dealer.web.orders.show', $order))->assertOk()->assertSee('100');
        $this->assertDatabaseHas('sales_order_items', [
            'sales_order_id' => $order->id, 'unit_price_snapshot' => '100.00',
        ]);
    }

    public function test_blade_review_rejects_invalid_quantity_and_missing_dealer_price_without_retail_fallback(): void
    {
        [$user, , $variant, , $price] = $this->fixture();
        $this->actingAs($user);
        $data = $this->quickData($variant);
        $this->post(route('dealer.web.quick.review'), [
            ...$data, 'items' => [['product_variant_id' => 999999999, 'quantity' => '2']],
        ])->assertRedirect();
        $this->assertFalse(session('review.can_submit'));
        $this->post(route('dealer.web.quick.review'), [
            ...$data, 'items' => [['product_variant_id' => $variant->id, 'quantity' => '1.5']],
        ])->assertSessionHasErrors('items.0.quantity');
        $price->update(['status' => 'inactive']);
        $retailList = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $retailList->id,
            'product_variant_id' => $variant->id, 'unit_price' => '50.00']);
        $this->post(route('dealer.web.quick.review'), $data)->assertRedirect();
        $this->assertFalse(session('review.can_submit'));
        $this->assertSame('DEALER_PRICE_NOT_FOUND', session('review.items.0.errors.0'));
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_excel_upload_preview_confirm_and_ownership(): void
    {
        [$user, $account, $variant] = $this->fixture();
        $this->actingAs($user);
        $ward = AdministrativeWard::query()->where('province_code', '79')->value('name');
        $path = $this->workbook([[$variant->sku, 'Receiver', '0900000000', '',
            'TP. Hồ Chí Minh', '', $ward, '1 Main Street', '2']]);
        $this->post(route('dealer.web.imports.store'), ['file' => new UploadedFile($path, 'orders.xlsx', null, null, true)])
            ->assertRedirect();
        $import = DealerOrderImport::query()->where('dealer_account_id', $account->id)->firstOrFail();
        $this->assertSame(1, $import->valid_order_count);
        $this->assertDatabaseCount('sales_orders', 0);
        $this->get(route('dealer.web.imports.show', $import))->assertOk();
        $stranger = User::factory()->customer()->create();
        $otherAccount = DealerAccount::factory()->create(['current_tier_id' => $account->current_tier_id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $otherAccount->id, 'user_id' => $stranger->id]);
        $this->actingAs($stranger);
        $this->get(route('dealer.web.imports.show', $import))->assertNotFound();
        $this->post(route('dealer.web.imports.confirm', $import), [
            'operation_key' => (string) Str::uuid(), 'preview_fingerprint' => $import->preview_fingerprint,
        ])->assertNotFound();
        $this->actingAs($user);
        $this->post(route('dealer.web.imports.confirm', $import), [
            'operation_key' => (string) Str::uuid(), 'preview_fingerprint' => $import->preview_fingerprint,
        ])->assertRedirect();
        $this->assertDatabaseHas('sales_orders', ['dealer_account_id' => $account->id,
            'order_source' => 'dealer_excel', 'payment_status' => 'paid']);
    }

    public function test_excel_import_rejects_csv_and_xls_under_current_service_contract(): void
    {
        [$user] = $this->fixture();
        $this->actingAs($user);
        foreach (['csv', 'xls'] as $extension) {
            $this->post(route('dealer.web.imports.store'), [
                'file' => UploadedFile::fake()->createWithContent('orders.'.$extension, 'SKU,Quantity'),
            ])->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_blade_import_displays_invalid_sku_and_prevents_confirmation(): void
    {
        [$user] = $this->fixture();
        $this->actingAs($user);
        $ward = AdministrativeWard::query()->where('province_code', '79')->value('name');
        $path = $this->workbook([['NO-SUCH-SKU', 'Receiver', '0900000000', '',
            'TP. Hồ Chí Minh', '', $ward, '1 Main Street', '2']]);
        $this->post(route('dealer.web.imports.store'), [
            'file' => new UploadedFile($path, 'invalid.xlsx', null, null, true),
        ])->assertRedirect();
        $import = DealerOrderImport::query()->firstOrFail();
        $this->assertSame(1, $import->invalid_order_count);
        $this->get(route('dealer.web.imports.show', $import))->assertOk()
            ->assertSee('SKU_NOT_FOUND');
        $this->post(route('dealer.web.imports.confirm', $import), [
            'operation_key' => (string) Str::uuid(), 'preview_fingerprint' => $import->preview_fingerprint,
        ])->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('sales_orders', 0);
    }
}
