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
use App\Models\SalesPromotion;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseServiceArea;
use App\Services\DealerOrderImportWorkbook;
use App\Services\DealerQuickOrderService;
use App\Services\DealerWalletService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PharData;
use Tests\TestCase;

class DealerOrderImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

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
    private function fixture(string $stock = '10'): array
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
        app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(), 'amount' => '100000.00', 'method' => 'other_manual'], $admin);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => $stock,
            'operation_key' => (string) Str::uuid()], $admin->id);

        return [$user, $account, $variant, $warehouse, $price];
    }

    /** @return list<string> */
    private function row(string $addressGroup, string $sku, string $quantity = '2'): array
    {
        $street = strtoupper(trim($addressGroup)) === 'PO-001'
            ? '1 Main Street' : strtoupper(trim($addressGroup)).' Main Street';

        return [$sku, 'Receiving Manager', '0900000000', 'receiver@example.com',
            'TP. Hồ Chí Minh', '', AdministrativeWard::query()->where('province_code', '79')->value('name'),
            $street, $quantity];
    }

    /** @param list<list<string>> $rows
     * @param  list<string>|null  $headers
     */
    private function workbook(array $rows, ?array $headers = null): string
    {
        $path = sys_get_temp_dir().'/dealer-order-import-'.Str::uuid().'.zip';
        $this->temporaryFiles[] = $path;
        app(DealerOrderImportWorkbook::class)->template($path);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ([$headers ?? DealerOrderImportWorkbook::HEADERS, ...$rows] as $rowIndex => $values) {
            $xml .= '<row r="'.($rowIndex + 1).'">';
            foreach ($values as $column => $value) {
                $letter = chr(65 + $column);
                $xml .= '<c r="'.$letter.($rowIndex + 1).'" t="inlineStr"><is><t>'
                    .htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $xml .= '</row>';
        }
        (new PharData($path))->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');

        return $path;
    }

    public function test_template_round_trip_and_no_commercial_columns(): void
    {
        $path = $this->workbook([]);
        $this->assertSame([], app(DealerOrderImportWorkbook::class)->parse($path));
        $archive = new PharData($path);
        $this->assertStringContainsString('numFmtId="49"', $archive['xl/styles.xml']->getContent());
        $template = sys_get_temp_dir().'/dealer-order-template-'.Str::uuid().'.zip';
        $this->temporaryFiles[] = $template;
        app(DealerOrderImportWorkbook::class)->template($template);
        $this->assertStringContainsString('<col min="3" max="3" style="1"',
            (new PharData($template))['xl/worksheets/sheet1.xml']->getContent());
        $headers = DealerOrderImportWorkbook::HEADERS;
        $this->assertSame(['SKU', 'Customer Name', 'Phone', 'Email', 'Province / City',
            'District', 'Ward', 'Street', 'Quantity'], $headers);
        $this->assertSame($headers, DealerOrderImportWorkbook::TEMPLATE_HEADERS);
        $this->assertNotContains('Order Code', $headers);
        $this->assertNotContains('Province Code', $headers);
        $this->assertNotContains('Ward Code', $headers);
        $this->assertNotContains('External Order Ref', $headers);
        $this->assertNotContains('Size', $headers);
        $this->assertNotContains('Style', $headers);
        $this->assertNotContains('Tier', $headers);
        $this->assertNotContains('Dealer Code', $headers);
        $this->assertNotContains('Unit Price', $headers);
        $this->assertNotContains('Warehouse', $headers);
    }

    public function test_excel_import_rejects_fractional_quantity_before_order_creation(): void
    {
        [$user, $account, $variant] = $this->fixture();
        Sanctum::actingAs($user);
        $path = $this->workbook([$this->row('PO-FRACTION', $variant->sku, '1.001')]);

        $this->postJson("/api/dealer/accounts/{$account->id}/order-imports", [
            'file' => new UploadedFile($path, 'fractional.xlsx', null, null, true),
        ])->assertCreated()->assertJsonPath('data.invalid_order_count', 1)
            ->assertJsonPath('data.groups.0.preview.errors.0.code', 'INVALID_QUANTITY');

        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_upload_preview_then_confirm_uses_shared_dealer_order_core(): void
    {
        [$user, $account, $variant, $warehouse] = $this->fixture();
        Sanctum::actingAs($user);
        $path = $this->workbook([$this->row('PO-001', $variant->sku)]);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";
        $uploaded = new UploadedFile($path, 'orders.xlsx', null, null, true);
        $preview = $this->postJson($url, ['file' => $uploaded])->assertCreated()
            ->assertJsonPath('data.import_mode', 'multi_order')
            ->assertJsonPath('data.valid_order_count', 1)
            ->assertJsonPath('data.groups.0.preview.grand_total', '200.00')->json('data');
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $repeatReview = app(DealerQuickOrderService::class)->review($user, $account,
            [['product_variant_id' => $variant->id, 'quantity' => '2']], $preview['groups'][0]['preview']['recipient']);
        $this->assertSame($preview['groups'][0]['preview']['review_fingerprint'], $repeatReview['review_fingerprint'],
            json_encode([$preview['groups'][0]['preview']['recipient'], $repeatReview['recipient_defaults']]));
        $confirmed = $this->postJson("$url/{$preview['id']}/confirm", [
            'operation_key' => (string) Str::uuid(),
            'preview_fingerprint' => $preview['preview_fingerprint'],
        ])->assertOk()->assertJsonPath('data.status', 'completed')->json('data');
        $this->assertDatabaseHas('sales_orders', ['id' => $confirmed['groups'][0]['sales_order_id'],
            'order_source' => 'dealer_excel', 'sales_channel' => 'dealer',
            'external_reference' => null, 'buyer_user_id' => $user->id]);
        $this->assertDatabaseCount('dealer_shipping_addresses', 0);
        $this->assertSame('GROUP-001', $preview['groups'][0]['external_reference']);
        $this->assertDatabaseHas('inventory_balances', ['warehouse_id' => $warehouse->id,
            'on_hand_quantity' => '10.000', 'reserved_quantity' => '2.000']);
    }

    public function test_excel_import_uses_address_resolved_warehouse_and_tier_price(): void
    {
        [$user, $account, $variant, , $price] = $this->fixture();
        $price->update(['unit_price' => '850000.00']);
        $hanoi = Warehouse::factory()->create(['dealer_price_adjustment_percent' => '3.00']);
        WarehouseServiceArea::query()->create(['warehouse_id' => $hanoi->id, 'province_code' => '1']);
        $admin = User::factory()->admin()->create();
        app(InventoryService::class)->receive(['warehouse_id' => $hanoi->id,
            'product_variant_id' => $variant->id, 'quantity' => '10',
            'operation_key' => (string) Str::uuid()], $admin->id);
        app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(),
            'amount' => '2000000.00', 'method' => 'other_manual'], $admin);
        Sanctum::actingAs($user);
        $hanoiRow = $this->row('PO-HN', $variant->sku);
        $hanoiRow[4] = 'Hà Nội';
        $hanoiRow[6] = AdministrativeWard::query()->where('province_code', '1')->value('name');
        $path = $this->workbook([$hanoiRow]);
        $base = "/api/dealer/accounts/{$account->id}/order-imports";
        $preview = $this->postJson($base, ['file' => new UploadedFile($path, 'hanoi.xlsx', null, null, true)])->assertCreated()
            ->assertJsonPath('data.groups.0.preview.warehouse.id', $hanoi->id)
            ->assertJsonPath('data.groups.0.preview.items.0.unit_price', '850000.00')
            ->json('data');
        $this->postJson("$base/{$preview['id']}/confirm", [
            'operation_key' => (string) Str::uuid(),
            'preview_fingerprint' => $preview['preview_fingerprint'],
        ])->assertOk()->assertJsonPath('data.status', 'completed');
        $orderId = SalesOrder::query()->where('dealer_account_id', $account->id)->value('id');
        $this->assertDatabaseHas('sales_orders', ['id' => $orderId, 'warehouse_id' => $hanoi->id]);
        $this->assertDatabaseHas('sales_order_items', ['sales_order_id' => $orderId,
            'unit_price_snapshot' => '850000.00']);
    }

    public function test_address_groups_lines_and_each_destination_resolves_its_own_warehouse(): void
    {
        [$user, $account, $variant, $hcm] = $this->fixture('20');
        $second = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $listId = PriceListItem::query()->where('product_variant_id', $variant->id)->value('price_list_id');
        PriceListItem::factory()->create(['price_list_id' => $listId, 'product_variant_id' => $second->id,
            'unit_price' => '100.00', 'minimum_quantity' => '1']);
        $hanoi = Warehouse::factory()->create(['dealer_price_adjustment_percent' => '3.00']);
        WarehouseServiceArea::query()->create(['warehouse_id' => $hanoi->id, 'province_code' => '1']);
        $admin = User::factory()->admin()->create();
        foreach ([[$hanoi, $variant], [$hanoi, $second], [$hcm, $second]] as [$warehouse, $stockVariant]) {
            app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
                'product_variant_id' => $stockVariant->id, 'quantity' => '20',
                'operation_key' => (string) Str::uuid()], $admin->id);
        }
        $wardHanoi = AdministrativeWard::query()->where('province_code', '1')->value('name');
        $wardHcm = AdministrativeWard::query()->where('province_code', '79')->value('name');
        $path = $this->workbook([
            [$variant->sku, 'Sang', '0901000000', '', 'Hà Nội', '', $wardHanoi, '1 Street', '2'],
            [$second->sku, 'Sang', '0901000000', '', 'Hà Nội', '', $wardHanoi, '1 Street', '2'],
            [$variant->sku, 'Đồng', '0902000000', '', 'TP Hồ Chí Minh', '', $wardHcm, '2 Street', '2'],
        ]);
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";

        $preview = $this->postJson($url, ['file' => new UploadedFile($path, 'two-destinations.xlsx', null, null, true)])
            ->assertCreated()->assertJsonPath('data.order_count', 2)
            ->assertJsonPath('data.groups.0.preview.warehouse.id', $hanoi->id)
            ->assertJsonPath('data.groups.1.preview.warehouse.id', $hcm->id)
            ->assertJsonPath('data.groups.0.preview.items.0.unit_price', '100.00')
            ->assertJsonPath('data.groups.1.preview.items.0.unit_price', '100.00')->json('data');
        $this->postJson("$url/{$preview['id']}/confirm", ['operation_key' => (string) Str::uuid(),
            'preview_fingerprint' => $preview['preview_fingerprint']])->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('sales_orders', ['external_reference' => null, 'warehouse_id' => $hanoi->id]);
        $this->assertDatabaseHas('sales_orders', ['external_reference' => null, 'warehouse_id' => $hcm->id]);
        $listed = $this->getJson("/api/dealer/accounts/{$account->id}/orders")
            ->assertOk()->assertJsonCount(2, 'data')->json('data');
        $hanoiOrder = collect($listed)->first(fn (array $order): bool => $order['warehouse']['code'] === $hanoi->code);
        $this->assertSame('Sang', $hanoiOrder['recipient_name']);
        $this->assertSame(2, $hanoiOrder['item_count']);
        $this->assertSame('4.000', $hanoiOrder['total_quantity']);
        $this->assertSame($hanoi->code, $hanoiOrder['warehouse']['code']);
        $this->assertSame('Đồng', collect($listed)->first(fn (array $order): bool => $order['warehouse']['code'] === $hcm->code)['recipient_name']);
    }

    public function test_automatic_promotion_discount_is_repriced_and_debited_by_shared_order_core(): void
    {
        [$user, $account, $variant] = $this->fixture();
        $hanoi = Warehouse::factory()->create(['dealer_price_adjustment_percent' => '3.00']);
        WarehouseServiceArea::query()->create(['warehouse_id' => $hanoi->id, 'province_code' => '1']);
        app(InventoryService::class)->receive(['warehouse_id' => $hanoi->id,
            'product_variant_id' => $variant->id, 'quantity' => '10',
            'operation_key' => (string) Str::uuid()], User::factory()->admin()->create()->id);
        $promotion = SalesPromotion::factory()->create(['discount_type' => 'fixed_amount',
            'discount_value' => '50.00', 'sales_scope' => 'dealer']);
        Sanctum::actingAs($user);
        $hanoiRow = $this->row('PO-PROMO', $variant->sku);
        $hanoiRow[4] = 'Hà Nội';
        $hanoiRow[6] = AdministrativeWard::query()->where('province_code', '1')->value('name');
        $path = $this->workbook([$hanoiRow]);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";
        $preview = $this->postJson($url, ['file' => new UploadedFile($path, 'promotion.xlsx', null, null, true)])
            ->assertCreated()->assertJsonPath('data.groups.0.preview.subtotal', '200.00')
            ->assertJsonPath('data.groups.0.preview.discount_total', '50.00')
            ->assertJsonPath('data.groups.0.preview.grand_total', '150.00')->json('data');
        $this->assertDatabaseCount('sales_promotion_redemptions', 0);
        $this->postJson("$url/{$preview['id']}/confirm", ['operation_key' => (string) Str::uuid(),
            'preview_fingerprint' => $preview['preview_fingerprint']])->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('sales_orders', ['order_source' => 'dealer_excel',
            'promotion_code_snapshot' => $promotion->code, 'discount_total' => '50.00', 'grand_total' => '150.00']);
        $this->assertDatabaseHas('payments', ['payment_method' => 'dealer_wallet', 'amount' => '150.00', 'status' => 'settled']);
    }

    public function test_excel_import_derives_and_reserves_gift_without_gift_columns(): void
    {
        [$user, $account, $buy, $warehouse] = $this->fixture();
        $gift = ProductVariant::factory()->create(['track_inventory' => true, 'sellable_dealer' => false]);
        $gift->product->update(['can_be_gift' => true, 'gift_only' => true]);
        $admin = User::factory()->admin()->create();
        app(InventoryService::class)->receive([
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $gift->id,
            'quantity' => '1', 'operation_key' => (string) Str::uuid(),
        ], $admin->id);
        $promotion = SalesPromotion::factory()->create([
            'discount_type' => 'buy_a_get_b', 'discount_value' => '0.00', 'sales_scope' => 'dealer',
        ]);
        $promotion->giftRule()->create([
            'buy_product_id' => $buy->product_id, 'buy_variant_id' => $buy->id,
            'minimum_buy_quantity' => '2.000', 'gift_product_id' => $gift->product_id,
            'gift_variant_id' => $gift->id, 'gift_quantity' => '1.000', 'repeat_per_multiple' => false,
        ]);
        Sanctum::actingAs($user);
        $path = $this->workbook([$this->row('PO-GIFT', $buy->sku)]);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";
        $preview = $this->postJson($url, ['file' => new UploadedFile($path, 'gift.xlsx', null, null, true)])
            ->assertCreated()->assertJsonPath('data.groups.0.preview.gift_item.quantity', '1.000')
            ->assertJsonPath('data.groups.0.preview.grand_total', '200.00')->json('data');
        $this->assertDatabaseCount('sales_order_items', 0);
        $confirmed = $this->postJson("$url/{$preview['id']}/confirm", [
            'operation_key' => (string) Str::uuid(),
            'preview_fingerprint' => $preview['preview_fingerprint'],
        ])->assertOk()->assertJsonPath('data.status', 'completed')->json('data');
        $orderId = $confirmed['groups'][0]['sales_order_id'];
        $this->assertDatabaseHas('sales_order_items', [
            'sales_order_id' => $orderId, 'product_variant_id' => $gift->id,
            'is_gift' => true, 'line_total' => '0.00',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $gift->id,
            'on_hand_quantity' => '1.000', 'reserved_quantity' => '1.000',
        ]);
        $this->assertDatabaseHas('payments', [
            'amount' => '200.00', 'payment_method' => 'dealer_wallet', 'status' => 'settled',
        ]);
        $this->assertDatabaseHas('payment_allocations', [
            'sales_order_id' => $orderId, 'allocated_amount' => '200.00',
        ]);
    }

    public function test_excel_order_rejects_voucher_code_column(): void
    {
        [$user, $account, $variant] = $this->fixture();
        $first = [...$this->row('PO-MIX', $variant->sku), 'CODE-A'];
        Sanctum::actingAs($user);
        $path = $this->workbook([$first], [...DealerOrderImportWorkbook::HEADERS, 'Voucher Code']);
        $this->postJson("/api/dealer/accounts/{$account->id}/order-imports", [
            'file' => new UploadedFile($path, 'mixed.xlsx', null, null, true),
        ])->assertConflict()->assertJsonPath('code', 'FORBIDDEN_IMPORT_COLUMN');
    }

    public function test_forbidden_columns_formula_and_text_cell_policy_fail_closed(): void
    {
        $forbidden = $this->workbook([], [...DealerOrderImportWorkbook::HEADERS, 'Tier']);
        try {
            app(DealerOrderImportWorkbook::class)->parse($forbidden);
            $this->fail('Forbidden header was accepted.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('FORBIDDEN_IMPORT_COLUMN', $exception->getResponse()->getData(true)['code']);
        }
        $formula = $this->workbook([$this->row('PO-001', 'SKU-A')]);
        $archive = new PharData($formula);
        $xml = $archive['xl/worksheets/sheet1.xml']->getContent();
        $xml = preg_replace('~<c r="I2".*?</c>~', '<c r="I2"><f>1+1</f><v>2</v></c>', $xml);
        $archive->addFromString('xl/worksheets/sheet1.xml', $xml);
        try {
            app(DealerOrderImportWorkbook::class)->parse($formula);
            $this->fail('Formula was accepted.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('FORMULA_NOT_ALLOWED', $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_different_recipients_split_groups_and_batch_stock_errors_block_confirmation(): void
    {
        [$user, $account, $variant] = $this->fixture('3');
        Sanctum::actingAs($user);
        $second = $this->row('PO-001', $variant->sku);
        $second[7] = 'Different Street';
        $path = $this->workbook([$this->row('PO-001', $variant->sku), $second]);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";
        $preview = $this->postJson($url, ['file' => new UploadedFile($path, 'duplicates.xlsx', null, null, true)])
            ->assertCreated()->assertJsonPath('data.order_count', 2)->json('data');
        $codes = array_column($preview['groups'][0]['preview']['errors'], 'code');
        $this->assertContains('IMPORT_BATCH_INSUFFICIENT_STOCK', $codes);
        $this->postJson("$url/{$preview['id']}/confirm", ['operation_key' => (string) Str::uuid(),
            'preview_fingerprint' => $preview['preview_fingerprint']])->assertConflict()
            ->assertJsonPath('code', 'DEALER_IMPORT_CHANGED');
        $this->assertDatabaseCount('sales_orders', 0);

        $batchPath = $this->workbook([$this->row('PO-002', $variant->sku), $this->row('PO-003', $variant->sku)]);
        $batch = $this->postJson($url, ['file' => new UploadedFile($batchPath, 'batch.xlsx', null, null, true)])
            ->assertCreated()->assertJsonPath('data.order_count', 2)->json('data');
        $this->assertContains('IMPORT_BATCH_INSUFFICIENT_STOCK',
            array_column($batch['groups'][0]['preview']['errors'], 'code'));
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_price_change_requires_revalidate_then_confirm_and_same_file_cannot_be_imported_twice(): void
    {
        [$user, $account, $variant, , $price] = $this->fixture();
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";
        $path = $this->workbook([$this->row('PO-001', $variant->sku)]);
        $preview = $this->postJson($url, ['file' => new UploadedFile($path, 'orders.xlsx', null, null, true)])
            ->assertCreated()->json('data');
        $price->update(['unit_price' => '110.00']);
        $key = (string) Str::uuid();
        $this->postJson("$url/{$preview['id']}/confirm", ['operation_key' => $key,
            'preview_fingerprint' => $preview['preview_fingerprint']])->assertConflict()
            ->assertJsonPath('code', 'DEALER_IMPORT_CHANGED');
        $this->assertDatabaseCount('sales_orders', 0);
        $fresh = $this->postJson("$url/{$preview['id']}/revalidate")->assertOk()
            ->assertJsonPath('data.groups.0.preview.grand_total', '220.00')->json('data');
        $this->postJson("$url/{$preview['id']}/confirm", ['operation_key' => (string) Str::uuid(),
            'preview_fingerprint' => $fresh['preview_fingerprint']])->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->postJson("$url/{$preview['id']}/confirm", ['operation_key' => (string) Str::uuid(),
            'preview_fingerprint' => $fresh['preview_fingerprint']])->assertOk();
        $this->assertDatabaseCount('sales_orders', 1);
        $this->postJson($url, ['file' => new UploadedFile($path, 'same-file.xlsx', null, null, true)])
            ->assertConflict()->assertJsonPath('code', 'IMPORT_FILE_ALREADY_COMPLETED');
        $otherFile = $this->workbook([$this->row('po-001', $variant->sku, '3')]);
        $next = $this->postJson($url, ['file' => new UploadedFile($otherFile, 'other.xlsx', null, null, true)])
            ->assertCreated()->assertJsonPath('data.valid_order_count', 1)->json('data');
        $this->postJson("$url/{$next['id']}/confirm", ['operation_key' => (string) Str::uuid(),
            'preview_fingerprint' => $next['preview_fingerprint']])->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseCount('sales_orders', 2);
    }

    public function test_multi_order_grouping_and_quick_order_have_equivalent_commercial_snapshots(): void
    {
        [$user, $account, $variant, $warehouse] = $this->fixture('20');
        $second = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $listId = PriceListItem::query()->where('product_variant_id', $variant->id)->value('price_list_id');
        PriceListItem::factory()->create(['price_list_id' => $listId, 'product_variant_id' => $second->id,
            'unit_price' => '50.00', 'minimum_quantity' => '1']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $second->id, 'quantity' => '20',
            'operation_key' => (string) Str::uuid()], User::factory()->admin()->create()->id);
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";
        $path = $this->workbook([$this->row(' PO-001 ', strtolower($variant->sku)),
            $this->row('PO-001', $second->sku, '3'), $this->row('PO-002', $variant->sku, '4')]);
        $preview = $this->postJson($url, ['file' => new UploadedFile($path, 'grouped.xlsx', null, null, true)])
            ->assertCreated()->assertJsonPath('data.order_count', 2)
            ->assertJsonPath('data.row_count', 3)->assertJsonPath('data.valid_order_count', 2)->json('data');
        $this->assertDatabaseCount('sales_order_items', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertSame([2, 1], array_map(fn (array $group): int => count($group['preview']['items']), $preview['groups']));
        $this->assertSame('350.00', $preview['groups'][0]['preview']['grand_total']);
        $confirmed = $this->postJson("$url/{$preview['id']}/confirm", [
            'operation_key' => (string) Str::uuid(), 'preview_fingerprint' => $preview['preview_fingerprint'],
        ])->assertOk()->json('data');
        $this->assertSame('completed', $confirmed['status'], json_encode($confirmed['groups'], JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('sales_orders', 2);
        $this->assertDatabaseHas('dealer_order_import_rows', ['sku_input' => $variant->sku,
            'product_variant_id' => $variant->id]);
        $imported = SalesOrder::query()->with('items')
            ->findOrFail($confirmed['groups'][0]['sales_order_id']);
        $this->assertSame('confirmed', $imported->order_status);
        $this->assertSame('paid', $imported->payment_status);
        $this->assertSame('reserved', $imported->fulfillment_status);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('dealer_wallet_transactions', 3);

        $lines = [['product_variant_id' => $variant->id, 'quantity' => '2'],
            ['product_variant_id' => $second->id, 'quantity' => '3']];
        $shipping = $preview['groups'][0]['preview']['recipient'];
        $review = app(DealerQuickOrderService::class)->review($user, $account, $lines, $shipping);
        $quick = app(DealerQuickOrderService::class)->submit($user, $account, [
            'operation_key' => (string) Str::uuid(), 'review_fingerprint' => $review['review_fingerprint'],
            'items' => $lines, ...$shipping,
        ]);
        $this->assertSame($quick->dealer_account_id, $imported->dealer_account_id);
        $this->assertSame($quick->effective_tier_code_snapshot, $imported->effective_tier_code_snapshot);
        $this->assertSame($quick->grand_total, $imported->grand_total);
        $this->assertSame($quick->warehouse_id, $imported->warehouse_id);
        $this->assertSame($quick->items()->orderBy('sku_snapshot')->pluck('unit_price_snapshot')->all(),
            $imported->items()->orderBy('sku_snapshot')->pluck('unit_price_snapshot')->all());
        $this->assertSame($quick->items()->orderBy('sku_snapshot')->pluck('minimum_quantity_snapshot')->all(),
            $imported->items()->orderBy('sku_snapshot')->pluck('minimum_quantity_snapshot')->all());
        $this->assertSame($quick->recipient_name, $imported->recipient_name);
        $this->assertSame($quick->recipient_phone, $imported->recipient_phone);
        $this->assertDatabaseHas('inventory_reservations', ['sales_order_id' => $imported->id,
            'status' => 'active']);
        $this->assertSame('dealer_excel', $imported->order_source);
        $this->assertSame('quick_order', $quick->order_source);
        $dealerOrders = $this->getJson("/api/dealer/accounts/{$account->id}/orders")
            ->assertOk()->assertJsonFragment(['id' => $imported->id])->json('data');
        $dealerImportOrder = collect($dealerOrders)->firstWhere('id', $imported->id);
        $this->assertSame('dealer_excel', $dealerImportOrder['order_source']);
        $this->assertNull($dealerImportOrder['external_reference']);
        $this->assertSame($imported->recipient_name, $dealerImportOrder['recipient_name']);
        $this->assertSame($imported->shipping_address_line1, $dealerImportOrder['shipping_address_line1']);
        $this->assertSame(2, $dealerImportOrder['item_count']);
        $this->assertSame('5.000', $dealerImportOrder['total_quantity']);
        Sanctum::actingAs(User::factory()->admin()->create());
        $adminOrders = $this->getJson('/api/admin/sales-orders?order_source=dealer_excel')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $imported->id])->json('data');
        $adminImportOrder = collect($adminOrders)->firstWhere('id', $imported->id);
        $this->assertNull($adminImportOrder['external_reference']);
        $this->assertSame('5.000', $adminImportOrder['total_quantity']);
        $this->assertDatabaseHas('audit_logs', ['target_type' => 'DealerOrderImport', 'target_id' => $preview['id'],
            'action' => 'COMPLETE']);
    }

    public function test_import_groups_by_normalized_phone_and_address_and_combines_duplicate_sku_quantities(): void
    {
        [$user, $account, $variant, $warehouse] = $this->fixture('20');
        $second = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $listId = PriceListItem::query()->where('product_variant_id', $variant->id)->value('price_list_id');
        PriceListItem::factory()->create(['price_list_id' => $listId, 'product_variant_id' => $second->id,
            'unit_price' => '50.00', 'minimum_quantity' => '1']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $second->id, 'quantity' => '20',
            'operation_key' => (string) Str::uuid()], User::factory()->admin()->create()->id);

        $formatted = $this->row('PO-001', $second->sku, '3');
        $formatted[2] = '0900-000-000';
        $formatted[7] = '  1 main street  ';
        $otherPhone = $this->row('PO-001', $variant->sku);
        $otherPhone[2] = '0900 000 001';
        $path = $this->workbook([$this->row('PO-001', $variant->sku), $formatted,
            $this->row('PO-002', $variant->sku), $this->row('PO-001', $variant->sku, '3'), $otherPhone]);
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";
        $preview = $this->postJson($url, ['file' => new UploadedFile($path, 'grouped.xlsx', null, null, true)])
            ->assertCreated()->assertJsonPath('data.row_count', 5)->assertJsonPath('data.order_count', 3)
            ->assertJsonPath('data.valid_order_count', 3)->json('data');
        $this->assertSame(['GROUP-001', 'GROUP-002', 'GROUP-003'],
            array_column($preview['groups'], 'external_reference'));
        $this->assertCount(2, $preview['groups'][0]['preview']['items']);
        $this->assertSame('5', collect($preview['groups'][0]['preview']['items'])
            ->firstWhere('product_variant_id', $variant->id)['quantity']);
        $this->assertDatabaseCount('sales_orders', 0);

        $confirmed = $this->postJson("$url/{$preview['id']}/confirm", [
            'operation_key' => (string) Str::uuid(), 'preview_fingerprint' => $preview['preview_fingerprint'],
        ])->assertOk()->assertJsonPath('data.status', 'completed')->json('data');
        $this->assertDatabaseCount('sales_orders', 3);
        $this->assertDatabaseCount('sales_order_items', 4);
        $this->assertDatabaseHas('sales_order_items', [
            'sales_order_id' => $confirmed['groups'][0]['sales_order_id'],
            'product_variant_id' => $variant->id, 'quantity' => '5.000',
        ]);
        $this->assertDatabaseHas('sales_orders', [
            'id' => $confirmed['groups'][0]['sales_order_id'], 'external_reference' => null,
        ]);
    }

    public function test_import_rejects_different_names_at_one_phone_and_address(): void
    {
        [$user, $account, $variant] = $this->fixture('20');
        $first = $this->row('PO-001', $variant->sku);
        $second = $first;
        $second[1] = 'Another Recipient';
        $path = $this->workbook([$first, $second]);
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";

        $preview = $this->postJson($url, ['file' => new UploadedFile($path, 'recipients.xlsx', null, null, true)])
            ->assertCreated()->assertJsonPath('data.order_count', 1)
            ->assertJsonPath('data.invalid_order_count', 1)->json('data');
        $this->assertContains('ORDER_GROUP_NAME_MISMATCH',
            array_column($preview['groups'][0]['preview']['errors'], 'code'));
        $this->postJson("$url/{$preview['id']}/confirm", [
            'operation_key' => (string) Str::uuid(), 'preview_fingerprint' => $preview['preview_fingerprint'],
        ])->assertConflict();
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('dealer_shipping_addresses', 0);
    }

    public function test_importing_500_customers_does_not_populate_the_dealer_address_book(): void
    {
        [$user, $account, $variant] = $this->fixture('1000');
        Sanctum::actingAs($user);
        $rows = [];
        for ($index = 1; $index <= 500; $index++) {
            $row = $this->row('PO-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), $variant->sku);
            $row[2] = '0900'.str_pad((string) $index, 6, '0', STR_PAD_LEFT);
            $rows[] = $row;
        }
        $path = $this->workbook($rows);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";
        $preview = $this->postJson($url, ['file' => new UploadedFile($path, '500-customers.xlsx', null, null, true)])
            ->assertCreated()->assertJsonPath('data.row_count', 500)
            ->assertJsonPath('data.order_count', 500)->json('data');

        $this->postJson("$url/{$preview['id']}/confirm", [
            'operation_key' => (string) Str::uuid(), 'preview_fingerprint' => $preview['preview_fingerprint'],
        ])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseCount('sales_orders', 500);
        $this->assertDatabaseCount('dealer_shipping_addresses', 0);
    }

    public function test_invalid_sku_and_quantity_report_the_excel_row_and_field(): void
    {
        [$user, $account, $variant] = $this->fixture();
        Sanctum::actingAs($user);
        $path = $this->workbook([$this->row('PO-001', 'UNKNOWN-SKU'),
            $this->row('PO-002', $variant->sku, '0')]);
        $preview = $this->postJson("/api/dealer/accounts/{$account->id}/order-imports", [
            'file' => new UploadedFile($path, 'invalid-rows.xlsx', null, null, true),
        ])->assertCreated()->assertJsonPath('data.invalid_order_count', 2)->json('data');
        $this->assertSame(2, $preview['groups'][0]['preview']['errors'][0]['row']);
        $this->assertSame('SKU', $preview['groups'][0]['preview']['errors'][0]['field']);
        $this->assertSame('SKU_NOT_FOUND', $preview['groups'][0]['preview']['errors'][0]['code']);
        $this->assertSame('UNKNOWN-SKU', $preview['groups'][0]['preview']['errors'][0]['value']);
        $this->assertSame(3, $preview['groups'][1]['preview']['errors'][0]['row']);
        $this->assertSame('Quantity', $preview['groups'][1]['preview']['errors'][0]['field']);
        $this->assertSame('INVALID_QUANTITY', $preview['groups'][1]['preview']['errors'][0]['code']);
        $this->assertSame('0', $preview['groups'][1]['preview']['errors'][0]['value']);
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_address_names_resolve_to_same_group_and_phone_keeps_leading_zero(): void
    {
        [$user, $account, $variant] = $this->fixture('10');
        Sanctum::actingAs($user);
        $first = $this->row('PO-001', $variant->sku);
        $second = $this->row('PO-001', $variant->sku, '3');
        $first[4] = ' TP Hồ Chí Minh ';
        $second[4] = 'Hồ Chí Minh';
        $second[7] = ' 1 MAIN STREET ';
        $preview = $this->postJson("/api/dealer/accounts/{$account->id}/order-imports", [
            'file' => new UploadedFile($this->workbook([$first, $second]), 'normalized.xlsx', null, null, true),
        ])->assertCreated()->assertJsonPath('data.order_count', 1)
            ->assertJsonPath('data.preview_summary.valid_row_count', 2)
            ->assertJsonPath('data.preview_summary.sku_count', 1)->json('data');
        $this->assertSame('0900000000', $preview['groups'][0]['preview']['recipient']['recipient_phone']);
        $this->assertSame('5', $preview['groups'][0]['preview']['items'][0]['quantity']);
    }

    public function test_same_phone_and_street_with_different_districts_create_two_orders(): void
    {
        [$user, $account, $variant] = $this->fixture();
        Sanctum::actingAs($user);
        $first = $this->row('PO-001', $variant->sku);
        $first[5] = 'Quận 1';
        $second = $first;
        $second[5] = 'Quận 2';
        $this->postJson("/api/dealer/accounts/{$account->id}/order-imports", [
            'file' => new UploadedFile($this->workbook([$first, $second]), 'districts.xlsx', null, null, true),
        ])->assertCreated()->assertJsonPath('data.order_count', 2);
    }

    public function test_two_customers_with_five_sku_rows_create_two_orders(): void
    {
        [$user, $account, $first, $warehouse] = $this->fixture('20');
        $listId = PriceListItem::query()->where('product_variant_id', $first->id)->value('price_list_id');
        $variants = [$first];
        $admin = User::factory()->admin()->create();
        for ($index = 0; $index < 2; $index++) {
            $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
            PriceListItem::factory()->create(['price_list_id' => $listId, 'product_variant_id' => $variant->id,
                'unit_price' => '100.00', 'minimum_quantity' => '1']);
            app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
                'product_variant_id' => $variant->id, 'quantity' => '20',
                'operation_key' => (string) Str::uuid()], $admin->id);
            $variants[] = $variant;
        }
        $rows = [$this->row('PO-001', $variants[0]->sku), $this->row('PO-001', $variants[1]->sku)];
        foreach ($variants as $variant) {
            $row = $this->row('PO-001', $variant->sku);
            $row[1] = 'Customer B';
            $row[2] = '0900000001';
            $rows[] = $row;
        }
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";
        $preview = $this->postJson($url, ['file' => new UploadedFile($this->workbook($rows),
            'two-customers.xlsx', null, null, true)])->assertCreated()->assertJsonPath('data.order_count', 2)
            ->assertJsonPath('data.preview_summary.sku_count', 3)->json('data');
        $this->assertSame([2, 3], array_map(fn (array $group): int => count($group['preview']['items']),
            $preview['groups']));
        $this->postJson("$url/{$preview['id']}/confirm", ['operation_key' => (string) Str::uuid(),
            'preview_fingerprint' => $preview['preview_fingerprint']])->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseCount('sales_orders', 2);
        $this->assertDatabaseCount('sales_order_items', 5);
    }

    public function test_invalid_province_and_ward_report_source_rows(): void
    {
        [$user, $account, $variant] = $this->fixture();
        Sanctum::actingAs($user);
        $province = $this->row('PO-001', $variant->sku);
        $province[4] = 'Tỉnh không tồn tại';
        $ward = $this->row('PO-002', $variant->sku);
        $ward[6] = AdministrativeWard::query()->where('province_code', '1')->value('name');
        $preview = $this->postJson("/api/dealer/accounts/{$account->id}/order-imports", [
            'file' => new UploadedFile($this->workbook([$province, $ward]), 'bad-address.xlsx', null, null, true),
        ])->assertCreated()->assertJsonPath('data.preview_summary.invalid_row_count', 2)->json('data');
        $this->assertEqualsCanonicalizing(['row' => 2, 'field' => 'Province / City', 'code' => 'PROVINCE_NOT_FOUND',
            'value' => 'Tỉnh không tồn tại'], $preview['groups'][0]['preview']['errors'][0]);
        $this->assertSame('WARD_PROVINCE_MISMATCH', $preview['groups'][1]['preview']['errors'][0]['code']);
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_zero_negative_decimal_and_text_quantities_are_rejected(): void
    {
        [$user, $account, $variant] = $this->fixture();
        Sanctum::actingAs($user);
        $rows = [];
        foreach (['0', '-1', '1.5', 'text'] as $index => $quantity) {
            $rows[] = $this->row('PO-'.($index + 1), $variant->sku, $quantity);
        }
        $preview = $this->postJson("/api/dealer/accounts/{$account->id}/order-imports", [
            'file' => new UploadedFile($this->workbook($rows), 'bad-quantities.xlsx', null, null, true),
        ])->assertCreated()->assertJsonPath('data.preview_summary.invalid_row_count', 4)->json('data');
        foreach ($preview['groups'] as $index => $group) {
            $this->assertSame($index + 2, $group['preview']['errors'][0]['row']);
            $this->assertSame('INVALID_QUANTITY', $group['preview']['errors'][0]['code']);
        }
    }

    public function test_conflicting_emails_in_one_group_block_confirmation(): void
    {
        [$user, $account, $variant] = $this->fixture();
        Sanctum::actingAs($user);
        $second = $this->row('PO-001', $variant->sku);
        $second[3] = 'other@example.com';
        $preview = $this->postJson("/api/dealer/accounts/{$account->id}/order-imports", [
            'file' => new UploadedFile($this->workbook([$this->row('PO-001', $variant->sku), $second]),
                'conflicting-email.xlsx', null, null, true),
        ])->assertCreated()->assertJsonPath('data.order_count', 1)->json('data');
        $this->assertSame('ORDER_GROUP_EMAIL_MISMATCH', $preview['groups'][0]['preview']['errors'][0]['code']);
    }

    public function test_pending_legacy_import_keeps_its_external_reference_even_when_it_starts_with_auto(): void
    {
        [$user, $account, $variant] = $this->fixture();
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";
        $path = $this->workbook([$this->row('PO-001', $variant->sku)]);
        $preview = $this->postJson($url, [
            'file' => new UploadedFile($path, 'legacy-reference.xlsx', null, null, true),
        ])->assertCreated()->json('data');
        $import = DealerOrderImport::query()->findOrFail($preview['id']);
        $import->rows()->update(['external_reference' => 'AUTO-LEGACY']);
        $import->groups()->update(['external_reference' => 'AUTO-LEGACY']);

        $fresh = $this->postJson("$url/{$preview['id']}/revalidate")->assertOk()->json('data');
        $confirmed = $this->postJson("$url/{$preview['id']}/confirm", [
            'operation_key' => (string) Str::uuid(), 'preview_fingerprint' => $fresh['preview_fingerprint'],
        ])->assertOk()->assertJsonPath('data.status', 'completed')->json('data');
        $this->assertDatabaseHas('sales_orders', [
            'id' => $confirmed['groups'][0]['sales_order_id'], 'external_reference' => 'AUTO-LEGACY',
        ]);
    }

    public function test_import_access_and_authority_fields_are_rejected(): void
    {
        [$user, $account, $variant] = $this->fixture();
        $other = User::factory()->customer()->create();
        Sanctum::actingAs($other);
        $path = $this->workbook([$this->row('PO-001', $variant->sku)]);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";
        $this->postJson($url, ['file' => new UploadedFile($path, 'orders.xlsx', null, null, true)])
            ->assertNotFound();
        Sanctum::actingAs($user);
        $this->postJson($url, ['file' => new UploadedFile($path, 'orders.xlsx', null, null, true),
            'tier_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('tier_id');
        $preview = $this->postJson($url, ['file' => new UploadedFile($path, 'orders.xlsx', null, null, true)])
            ->assertCreated()->json('data');
        Sanctum::actingAs($other);
        $this->getJson("$url/{$preview['id']}")->assertNotFound();
        $this->postJson("$url/{$preview['id']}/confirm", ['operation_key' => (string) Str::uuid(),
            'preview_fingerprint' => $preview['preview_fingerprint']])->assertNotFound();
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_parser_rejects_missing_duplicate_and_unknown_headers_and_numeric_phone(): void
    {
        $parser = app(DealerOrderImportWorkbook::class);
        foreach ([
            [[...array_slice(DealerOrderImportWorkbook::HEADERS, 1)], 'MISSING_REQUIRED_COLUMN'],
            [[...DealerOrderImportWorkbook::HEADERS, 'SKU'], 'DUPLICATE_COLUMN'],
            [[...DealerOrderImportWorkbook::HEADERS, 'Unit Price'], 'FORBIDDEN_IMPORT_COLUMN'],
            [[...DealerOrderImportWorkbook::HEADERS, 'Dealer Code'], 'FORBIDDEN_IMPORT_COLUMN'],
            [[...DealerOrderImportWorkbook::HEADERS, 'External Order Ref'], 'FORBIDDEN_IMPORT_COLUMN'],
            [[...DealerOrderImportWorkbook::HEADERS, 'Order Code'], 'FORBIDDEN_IMPORT_COLUMN'],
            [[...DealerOrderImportWorkbook::HEADERS, 'Province Code'], 'FORBIDDEN_IMPORT_COLUMN'],
            [[...DealerOrderImportWorkbook::HEADERS, 'Ward Code'], 'FORBIDDEN_IMPORT_COLUMN'],
            [[...DealerOrderImportWorkbook::HEADERS, 'Zip Code'], 'FORBIDDEN_IMPORT_COLUMN'],
            [[...DealerOrderImportWorkbook::HEADERS, 'Size'], 'FORBIDDEN_IMPORT_COLUMN'],
            [[...DealerOrderImportWorkbook::HEADERS, 'Style'], 'FORBIDDEN_IMPORT_COLUMN'],
            [[...DealerOrderImportWorkbook::HEADERS, 'Surprise'], 'UNKNOWN_COLUMN'],
        ] as [$headers, $expected]) {
            try {
                $parser->parse($this->workbook([], $headers));
                $this->fail("Header error $expected was not raised.");
            } catch (HttpResponseException $exception) {
                $this->assertSame($expected, $exception->getResponse()->getData(true)['code']);
            }
        }
        $path = $this->workbook([$this->row('PO-001', 'SKU-A')]);
        $archive = new PharData($path);
        $xml = $archive['xl/worksheets/sheet1.xml']->getContent();
        $archive->addFromString('xl/worksheets/sheet1.xml', str_replace(
            '<c r="C2" t="inlineStr"><is><t>0900000000</t></is></c>',
            '<c r="C2"><v>900000000</v></c>', $xml));
        try {
            $parser->parse($path);
            $this->fail('Numeric phone was accepted.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('TEXT_CELL_REQUIRED', $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_partial_completion_revalidates_and_retries_without_duplicating_created_group(): void
    {
        [$user, $account, $variant, , $price] = $this->fixture('10');
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/order-imports";
        $path = $this->workbook([$this->row('PO-001', $variant->sku),
            $this->row('PO-002', $variant->sku)]);
        $preview = $this->postJson($url, ['file' => new UploadedFile($path, 'partial.xlsx', null, null, true)])
            ->assertCreated()->json('data');
        $changed = false;
        SalesOrder::updated(function (SalesOrder $order) use ($price, &$changed): void {
            if (! $changed && $order->order_source === 'dealer_excel' && $order->order_status === 'confirmed') {
                $changed = true;
                $price->update(['unit_price' => '120.00']);
            }
        });
        try {
            $partial = $this->postJson("$url/{$preview['id']}/confirm", [
                'operation_key' => (string) Str::uuid(),
                'preview_fingerprint' => $preview['preview_fingerprint'],
            ])->assertOk()->assertJsonPath('data.status', 'partially_completed')->json('data');
        } finally {
            SalesOrder::flushEventListeners();
        }
        $this->assertDatabaseCount('sales_orders', 1);
        $this->assertCount(1, array_filter($partial['groups'],
            fn (array $group): bool => $group['sales_order_id'] !== null));
        $fresh = $this->postJson("$url/{$preview['id']}/revalidate")->assertOk()->json('data');
        $this->assertSame('240.00', $fresh['groups'][1]['preview']['grand_total']);
        $complete = $this->postJson("$url/{$preview['id']}/confirm", [
            'operation_key' => (string) Str::uuid(),
            'preview_fingerprint' => $fresh['preview_fingerprint'],
        ])->assertOk()->assertJsonPath('data.status', 'completed')->json('data');
        $this->assertDatabaseCount('sales_orders', 2);
        $this->assertSame($partial['groups'][0]['sales_order_id'], $complete['groups'][0]['sales_order_id']);
    }

    public function test_upload_enforces_file_type_and_limits_and_deletes_private_temporary_file(): void
    {
        [$user, $account, $variant] = $this->fixture();
        Sanctum::actingAs($user);
        Storage::fake('local');
        $url = "/api/dealer/accounts/{$account->id}/order-imports";
        $invalid = tempnam(sys_get_temp_dir(), 'invalid-xlsx-');
        $this->temporaryFiles[] = $invalid;
        file_put_contents($invalid, 'not an Excel workbook');
        $this->postJson($url, ['file' => new UploadedFile($invalid, 'fake.xlsx', null, null, true)])
            ->assertConflict()->assertJsonPath('code', 'INVALID_XLSX');
        $this->assertSame([], Storage::disk('local')->files('dealer-order-import-tmp'));

        $path = $this->workbook([$this->row('PO-001', $variant->sku),
            $this->row('PO-002', $variant->sku)]);
        config()->set('dealer_order_import.max_rows', 1);
        $this->postJson($url, ['file' => new UploadedFile($path, 'too-many-rows.xlsx', null, null, true)])
            ->assertConflict()->assertJsonPath('code', 'IMPORT_ROW_LIMIT');
        $this->assertSame([], Storage::disk('local')->files('dealer-order-import-tmp'));
        config()->set('dealer_order_import.max_rows', 1000);
        config()->set('dealer_order_import.max_orders', 1);
        $this->postJson($url, ['file' => new UploadedFile($path, 'too-many-orders.xlsx', null, null, true)])
            ->assertConflict()->assertJsonPath('code', 'IMPORT_ORDER_LIMIT');
        $this->assertDatabaseCount('dealer_order_imports', 0);
        $this->assertSame([], Storage::disk('local')->files('dealer-order-import-tmp'));
    }
}
