<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReturnRefundTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{int, int, User} */
    private function order(): array
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $warehouse = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create(['track_inventory' => true]);
        $list = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'unit_price' => '100.00']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id, 'quantity' => '10',
            'operation_key' => (string) Str::uuid()], $admin->id);
        $id = $this->postJson('/api/admin/sales-orders', [
            'operation_key' => (string) Str::uuid(), 'sales_channel' => 'retail',
            'buyer_user_id' => User::factory()->customer()->create()->id,
            'warehouse_id' => $warehouse->id, 'currency' => 'VND', 'recipient_name' => 'Buyer',
            'recipient_phone' => '0900000000', 'shipping_address_line1' => '1 Street',
            'shipping_city' => 'HCM', 'shipping_province' => 'HCM', 'shipping_country' => 'VN',
            'items' => [['sku' => $variant->sku, 'quantity' => '2']],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/sales-orders/{$id}/confirm", ['operation_key' => (string) Str::uuid()])->assertOk();
        $itemId = DB::table('sales_order_items')->where('sales_order_id', $id)->value('id');

        return [$id, $itemId, $admin];
    }

    /** @return array<string, mixed> */
    private function refund(string $amount = '100.00', ?string $key = null): array
    {
        return ['operation_key' => $key ?? (string) Str::uuid(), 'amount' => $amount,
            'refund_method' => 'bank_transfer', 'reason' => 'order_cancel'];
    }

    /** @return array<string, mixed> */
    private function salesReturn(int $itemId, string $quantity = '1', string $restock = '1', ?string $key = null): array
    {
        return ['operation_key' => $key ?? (string) Str::uuid(), 'reason' => 'Customer returned goods',
            'items' => [['item_id' => $itemId, 'quantity' => $quantity, 'restock_quantity' => $restock,
                ...((float) $restock < (float) $quantity ? ['non_restock_reason_code' => 'damaged'] : [])]]];
    }

    public function test_refund_is_allocated_to_settled_payment_without_changing_gross_payment(): void
    {
        [$id, , $admin] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$id}/refunds", $this->refund())
            ->assertConflict()->assertJsonPath('code', 'NOTHING_TO_REFUND');
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '200.00', 'payment_method' => 'cash',
        ])->assertCreated();
        $body = $this->refund();
        $first = $this->postJson("/api/admin/sales-orders/{$id}/refunds", $body)->assertCreated()
            ->assertJsonPath('data.summary.paid_amount', '200.00')
            ->assertJsonPath('data.summary.refunded_amount', '100.00')
            ->assertJsonPath('data.summary.refundable_amount', '100.00')
            ->assertJsonPath('data.summary.outstanding_amount', '0.00')
            ->assertJsonPath('data.summary.payment_status', 'paid')
            ->assertJsonPath('data.summary.refund_status', 'partially_refunded');
        $this->postJson("/api/admin/sales-orders/{$id}/refunds", $body)->assertCreated()
            ->assertJsonPath('data.refund.id', $first->json('data.refund.id'));
        $this->postJson("/api/admin/sales-orders/{$id}/refunds", [...$body, 'amount' => '99.00'])
            ->assertConflict()->assertJsonPath('code', 'REFUND_OPERATION_CONFLICT');
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseHas('payments', ['amount' => '200.00', 'status' => 'settled']);
        $this->assertDatabaseHas('refund_allocations', ['amount' => '100.00']);
        $this->postJson("/api/admin/sales-orders/{$id}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Customer request',
        ])->assertConflict()->assertJsonPath('code', 'PAID_ORDER_REQUIRES_REFUND');
        $this->postJson("/api/admin/sales-orders/{$id}/refunds", $this->refund())->assertCreated()
            ->assertJsonPath('data.summary.refund_status', 'fully_refunded');
        $this->postJson("/api/admin/sales-orders/{$id}/cancel", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Customer request',
        ])->assertOk()->assertJsonPath('data.order_status', 'cancelled');
        Sanctum::actingAs($admin);
    }

    public function test_return_restock_is_independent_of_refund_and_uses_fulfilled_quantity(): void
    {
        [$id, $itemId] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$id}/returns", $this->salesReturn($itemId))
            ->assertConflict()->assertJsonPath('code', 'RETURN_QUANTITY_EXCEEDS_FULFILLED');
        $this->postJson("/api/admin/sales-orders/{$id}/fulfill", [
            'operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '2']],
        ])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$id}/returns", $this->salesReturn($itemId, '1.001', '0'))
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->postJson("/api/admin/sales-orders/{$id}/returns", $this->salesReturn($itemId, '1', '0.5'))
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.restock_quantity');
        $this->assertDatabaseCount('sales_returns', 0);
        $before = DB::table('inventory_balances')->first();
        $body = $this->salesReturn($itemId, '2', '1');
        $first = $this->postJson("/api/admin/sales-orders/{$id}/returns", $body)->assertCreated()
            ->assertJsonPath('data.items.0.restock_quantity', '1.000')
            ->assertJsonPath('data.items.0.non_restock_quantity', '1.000');
        $this->postJson("/api/admin/sales-orders/{$id}/returns", $body)->assertCreated()
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame('1.000', bcsub((string) DB::table('inventory_balances')->first()->on_hand_quantity,
            (string) $before->on_hand_quantity, 3));
        $this->assertEquals($before->reserved_quantity, DB::table('inventory_balances')->first()->reserved_quantity);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'SALES_RETURN', 'quantity' => '1.000']);
        $this->assertDatabaseCount('refunds', 0);
        $this->postJson("/api/admin/sales-orders/{$id}/returns", $this->salesReturn($itemId))
            ->assertConflict()->assertJsonPath('code', 'RETURN_QUANTITY_EXCEEDS_FULFILLED');
        $this->postJson("/api/admin/sales-orders/{$id}/returns", [...$body, 'reason' => 'Different'])
            ->assertConflict()->assertJsonPath('code', 'RETURN_OPERATION_CONFLICT');
    }

    public function test_pending_inspection_reserves_returnable_quantity_without_restocking_or_refunding(): void
    {
        [$orderId, $itemId] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$orderId}/fulfill", [
            'operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '2']],
        ])->assertOk();
        $before = DB::table('inventory_balances')->first()->on_hand_quantity;
        $body = $this->salesReturn($itemId, '2');
        $body['processing_mode'] = 'pending_inspection';
        unset($body['items'][0]['restock_quantity']);
        $response = $this->postJson("/api/admin/sales-orders/{$orderId}/returns", $body)
            ->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.items.0.stock_movement_id', null);
        $this->assertEquals($before, DB::table('inventory_balances')->first()->on_hand_quantity);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseMissing('stock_movements', ['reference_type' => 'SALES_RETURN_ITEM',
            'reference_id' => (string) $response->json('data.items.0.id')]);
        $this->getJson("/api/admin/sales-orders/{$orderId}/returnable-items")
            ->assertOk()->assertJsonPath('data.0.returnable_quantity', '0.000');
        $this->postJson("/api/admin/sales-orders/{$orderId}/returns", $this->salesReturn($itemId))
            ->assertConflict()->assertJsonPath('code', 'RETURN_QUANTITY_EXCEEDS_FULFILLED');
    }

    public function test_inspection_processes_partial_restock_once_with_reason_and_audit(): void
    {
        [$orderId, $itemId] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$orderId}/fulfill", [
            'operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '2']],
        ])->assertOk();
        $body = $this->salesReturn($itemId, '2');
        $body['processing_mode'] = 'pending_inspection';
        $created = $this->postJson("/api/admin/sales-orders/{$orderId}/returns", $body)->assertCreated();
        $returnId = $created->json('data.id');
        $returnItemId = $created->json('data.items.0.id');
        $before = DB::table('inventory_balances')->first()->on_hand_quantity;
        $key = (string) Str::uuid();
        $decision = ['operation_key' => $key, 'items' => [[
            'return_item_id' => $returnItemId, 'restock_quantity' => '1',
            'non_restock_reason_code' => 'damaged', 'non_restock_note' => 'Broken lid',
        ]]];
        $this->postJson("/api/admin/returns/{$returnId}/process", $decision)->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.items.0.restock_quantity', '1.000')
            ->assertJsonPath('data.items.0.non_restock_quantity', '1.000')
            ->assertJsonPath('data.items.0.non_restock_reason_code', 'damaged');
        $this->assertSame('1.000', bcsub((string) DB::table('inventory_balances')->first()->on_hand_quantity, (string) $before, 3));
        $this->assertDatabaseHas('stock_movements', ['reference_type' => 'SALES_RETURN_ITEM',
            'reference_id' => (string) $returnItemId, 'quantity' => '1.000']);
        $this->postJson("/api/admin/returns/{$returnId}/process", $decision)->assertOk();
        $this->postJson("/api/admin/returns/{$returnId}/process", [...$decision, 'operation_key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'RETURN_ALREADY_PROCESSED');
        $this->assertSame('1.000', bcsub((string) DB::table('inventory_balances')->first()->on_hand_quantity, (string) $before, 3));
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_inspection_requires_reason_for_non_restock_and_valid_quantity(): void
    {
        [$orderId, $itemId] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$orderId}/fulfill", [
            'operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '2']],
        ])->assertOk();
        $body = $this->salesReturn($itemId, '2');
        $body['processing_mode'] = 'pending_inspection';
        $created = $this->postJson("/api/admin/sales-orders/{$orderId}/returns", $body)->assertCreated();
        $returnId = $created->json('data.id');
        $returnItemId = $created->json('data.items.0.id');
        $endpoint = "/api/admin/returns/{$returnId}/process";
        $this->postJson($endpoint, ['operation_key' => (string) Str::uuid(), 'items' => [[
            'return_item_id' => $returnItemId, 'restock_quantity' => '3',
        ]]])->assertUnprocessable()->assertJsonValidationErrors('items.0.restock_quantity');
        $this->postJson($endpoint, ['operation_key' => (string) Str::uuid(), 'items' => [[
            'return_item_id' => $returnItemId, 'restock_quantity' => '0',
        ]]])->assertUnprocessable()->assertJsonValidationErrors('items.0.non_restock_reason_code');
        $this->postJson($endpoint, ['operation_key' => (string) Str::uuid(), 'items' => [[
            'return_item_id' => $returnItemId, 'restock_quantity' => '0', 'non_restock_reason_code' => 'other',
        ]]])->assertUnprocessable()->assertJsonValidationErrors('items.0.non_restock_note');
        $this->assertDatabaseHas('sales_returns', ['id' => $returnId, 'status' => 'pending']);
        $this->assertDatabaseMissing('stock_movements', ['reference_type' => 'SALES_RETURN_ITEM',
            'reference_id' => (string) $returnItemId]);
    }

    public function test_inspection_can_complete_without_restock_and_keep_refund_separate(): void
    {
        [$orderId, $itemId] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$orderId}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '200.00', 'payment_method' => 'cash',
        ])->assertCreated();
        $this->postJson("/api/admin/sales-orders/{$orderId}/fulfill", [
            'operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '1']],
        ])->assertOk();
        $body = $this->salesReturn($itemId);
        $body['processing_mode'] = 'pending_inspection';
        $created = $this->postJson("/api/admin/sales-orders/{$orderId}/returns", $body)->assertCreated();
        $returnId = $created->json('data.id');
        $this->postJson("/api/admin/returns/{$returnId}/process", [
            'operation_key' => (string) Str::uuid(), 'items' => [[
                'return_item_id' => $created->json('data.items.0.id'), 'restock_quantity' => '0',
                'non_restock_reason_code' => 'unsellable',
            ]],
        ])->assertOk()->assertJsonPath('data.items.0.stock_movement_id', null)
            ->assertJsonPath('data.items.0.non_restock_quantity', '1.000');
        $this->assertDatabaseCount('refunds', 0);
        $this->postJson("/api/admin/sales-orders/{$orderId}/refunds", [
            ...$this->refund('100.00'), 'return_id' => $returnId,
        ])->assertCreated();
        $this->getJson("/api/admin/returns/{$returnId}")->assertOk()
            ->assertJsonPath('data.refunded_amount', 100);
    }

    public function test_inspection_can_restock_all_and_immediate_non_restock_requires_a_reason(): void
    {
        [$orderId, $itemId] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$orderId}/fulfill", [
            'operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '2']],
        ])->assertOk();
        $invalid = $this->salesReturn($itemId, '1', '0');
        unset($invalid['items'][0]['non_restock_reason_code']);
        $this->postJson("/api/admin/sales-orders/{$orderId}/returns", $invalid)
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.non_restock_reason_code');
        $body = $this->salesReturn($itemId, '2');
        $body['processing_mode'] = 'pending_inspection';
        $created = $this->postJson("/api/admin/sales-orders/{$orderId}/returns", $body)->assertCreated();
        $returnId = $created->json('data.id');
        $returnItemId = $created->json('data.items.0.id');
        $before = DB::table('inventory_balances')->first()->on_hand_quantity;
        $this->postJson("/api/admin/returns/{$returnId}/process", [
            'operation_key' => (string) Str::uuid(),
            'items' => [['return_item_id' => $returnItemId, 'restock_quantity' => '2']],
        ])->assertOk()->assertJsonPath('data.items.0.non_restock_quantity', '0.000');
        $this->assertSame('2.000', bcsub((string) DB::table('inventory_balances')->first()->on_hand_quantity, (string) $before, 3));
    }

    public function test_legacy_return_operation_key_still_replays(): void
    {
        [$orderId, $itemId, $admin] = $this->order();
        $body = $this->salesReturn($itemId);
        $rows = [['item_id' => $itemId, 'quantity' => '1.000', 'restock_quantity' => '1.000']];
        $legacyFingerprint = hash('sha256', json_encode([
            $orderId, $body['reason'], null, $rows,
        ], JSON_THROW_ON_ERROR));
        $return = SalesReturn::create([
            'return_code' => 'RET'.Str::upper((string) Str::ulid()),
            'sales_order_id' => $orderId,
            'warehouse_id' => DB::table('sales_orders')->where('id', $orderId)->value('warehouse_id'),
            'status' => 'completed', 'reason' => $body['reason'],
            'processed_by_user_id' => $admin->id,
            'operation_key' => $body['operation_key'],
            'request_fingerprint' => $legacyFingerprint,
            'completed_at' => now(),
        ]);
        $this->postJson("/api/admin/sales-orders/{$orderId}/returns", $body)->assertCreated()
            ->assertJsonPath('data.id', $return->id);
        $this->assertDatabaseCount('sales_returns', 1);
    }

    public function test_linked_refund_cannot_exceed_historical_return_value(): void
    {
        [$id, $itemId] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '200.00', 'payment_method' => 'cash',
        ])->assertCreated();
        $this->postJson("/api/admin/sales-orders/{$id}/fulfill", [
            'operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '1']],
        ])->assertOk();
        $returnId = $this->postJson("/api/admin/sales-orders/{$id}/returns", $this->salesReturn($itemId))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/admin/sales-orders/{$id}/refunds", [...$this->refund('101.00'), 'return_id' => $returnId])
            ->assertConflict()->assertJsonPath('code', 'REFUND_EXCEEDS_RETURN_VALUE');
        $this->postJson("/api/admin/sales-orders/{$id}/refunds", [...$this->refund('60.00'), 'return_id' => $returnId])
            ->assertCreated();
        $this->postJson("/api/admin/sales-orders/{$id}/refunds", [...$this->refund('41.00'), 'return_id' => $returnId])
            ->assertConflict()->assertJsonPath('code', 'REFUND_EXCEEDS_RETURN_VALUE');
        $this->assertDatabaseHas('refunds', ['sales_return_id' => $returnId, 'amount' => '60.00']);
        try {
            DB::table('sales_returns')->where('id', $returnId)->delete();
            $this->fail('Completed return was deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('sales_returns', ['id' => $returnId]);
        }
    }

    public function test_retail_owner_sees_safe_refund_history_but_cannot_mutate_refund_or_return(): void
    {
        [$id] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '200.00', 'payment_method' => 'cash',
        ])->assertCreated();
        $this->postJson("/api/admin/sales-orders/{$id}/refunds", [
            ...$this->refund('50.00'), 'note' => 'Internal only',
            'external_reference' => 'BANK-PRIVATE',
        ])->assertCreated();
        $buyer = User::findOrFail(DB::table('sales_orders')->where('id', $id)->value('buyer_user_id'));
        Sanctum::actingAs($buyer);
        $this->getJson("/api/retail/orders/{$id}")->assertOk()
            ->assertJsonPath('data.refunded_amount', '50.00')
            ->assertJsonPath('data.net_settled_amount', '150.00')
            ->assertJsonPath('data.refunds.0.amount', '50.00')
            ->assertJsonMissing(['Internal only', 'BANK-PRIVATE']);
        $this->postJson("/api/admin/sales-orders/{$id}/refunds", $this->refund())->assertForbidden();
        $this->postJson("/api/admin/sales-orders/{$id}/returns", [
            'operation_key' => (string) Str::uuid(), 'reason' => 'Attempt',
            'items' => [['item_id' => 1, 'quantity' => '1', 'restock_quantity' => '1']],
        ])->assertForbidden();
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->getJson("/api/retail/orders/{$id}")->assertNotFound();
    }

    public function test_completed_refund_and_return_facts_are_immutable_and_duplicate_lines_are_rejected(): void
    {
        [$id, $itemId] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '200.00', 'payment_method' => 'cash',
        ])->assertCreated();
        $refundId = $this->postJson("/api/admin/sales-orders/{$id}/refunds", $this->refund('50.00'))
            ->assertCreated()->json('data.refund.id');
        try {
            DB::table('refunds')->where('id', $refundId)->update(['amount' => '1.00']);
            $this->fail('Completed refund was updated.');
        } catch (QueryException) {
            $this->assertDatabaseHas('refunds', ['id' => $refundId, 'amount' => '50.00']);
        }
        $this->postJson("/api/admin/sales-orders/{$id}/fulfill", [
            'operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '2']],
        ])->assertOk();
        $duplicate = $this->salesReturn($itemId);
        $duplicate['items'][] = $duplicate['items'][0];
        $this->postJson("/api/admin/sales-orders/{$id}/returns", $duplicate)
            ->assertConflict()->assertJsonPath('code', 'DUPLICATE_RETURN_ITEM');
        $returnId = $this->postJson("/api/admin/sales-orders/{$id}/returns", $this->salesReturn($itemId))
            ->assertCreated()->json('data.id');
        try {
            DB::table('sales_return_items')->where('sales_return_id', $returnId)->update(['quantity' => '2.000']);
            $this->fail('Completed return item was updated.');
        } catch (QueryException) {
            $this->assertDatabaseHas('sales_return_items', ['sales_return_id' => $returnId, 'quantity' => '1.000']);
        }
    }

    public function test_new_payment_after_full_refund_recomputes_refund_status_without_changing_gross_rules(): void
    {
        [$id] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '50.00', 'payment_method' => 'cash',
        ])->assertCreated();
        $this->postJson("/api/admin/sales-orders/{$id}/refunds", $this->refund('50.00'))
            ->assertCreated()->assertJsonPath('data.summary.refund_status', 'fully_refunded');
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '150.00', 'payment_method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.summary.refund_status', 'partially_refunded')
            ->assertJsonPath('data.summary.paid_amount', '200.00')
            ->assertJsonPath('data.summary.refunded_amount', '50.00')
            ->assertJsonPath('data.summary.outstanding_amount', '0.00');
        $this->assertDatabaseHas('sales_orders', ['id' => $id, 'payment_status' => 'paid',
            'refund_status' => 'partially_refunded']);
    }

    public function test_reconciliation_repairs_only_derived_refund_status_and_validates_return_movements(): void
    {
        [$id, $itemId] = $this->order();
        $this->postJson("/api/admin/sales-orders/{$id}/payments", [
            'operation_key' => (string) Str::uuid(), 'amount' => '200.00', 'payment_method' => 'cash',
        ])->assertCreated();
        $this->postJson("/api/admin/sales-orders/{$id}/refunds", $this->refund('50.00'))->assertCreated();
        $this->postJson("/api/admin/sales-orders/{$id}/fulfill", [
            'operation_key' => (string) Str::uuid(), 'items' => [['item_id' => $itemId, 'quantity' => '2']],
        ])->assertOk();
        $this->postJson("/api/admin/sales-orders/{$id}/returns", $this->salesReturn($itemId))
            ->assertCreated();
        $this->artisan('returns:reconcile', ['--dry-run' => true])->assertExitCode(0);
        DB::table('sales_orders')->where('id', $id)->update(['refund_status' => 'none']);
        $this->artisan('refunds:reconcile-orders', ['--dry-run' => true])->assertExitCode(0);
        $this->assertDatabaseHas('sales_orders', ['id' => $id, 'refund_status' => 'none']);
        $this->artisan('refunds:reconcile-orders', ['--apply' => true])->assertExitCode(0);
        $this->assertDatabaseHas('sales_orders', ['id' => $id, 'refund_status' => 'partially_refunded']);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseCount('sales_returns', 1);
    }
}
