<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerTier;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DealerWalletService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealerWalletTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{User, DealerAccount, User} */
    private function fixture(): array
    {
        $user = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => DealerTier::factory()->create()->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $admin = User::factory()->admin()->create();

        return [$user, $account, $admin];
    }

    public function test_admin_deposit_is_idempotent_and_dealer_can_read_wallet_ledger(): void
    {
        [$user, $account, $admin] = $this->fixture();
        $key = (string) Str::uuid();
        Sanctum::actingAs($admin);
        $body = ['operation_key' => $key, 'amount' => '1000.00', 'method' => 'bank_transfer', 'external_reference' => 'BANK-001'];
        $first = $this->postJson("/api/admin/dealers/{$account->id}/wallet/deposits", $body)->assertCreated()->assertJsonPath('data.amount', '1000.00')->json('data');
        $this->postJson("/api/admin/dealers/{$account->id}/wallet/deposits", $body)->assertCreated()->assertJsonPath('data.deposit_code', $first['deposit_code']);
        $this->assertDatabaseCount('dealer_wallet_deposits', 1);
        $this->assertDatabaseCount('dealer_wallet_transactions', 1);
        Sanctum::actingAs($user);
        $this->getJson("/api/dealer/accounts/{$account->id}/wallet")->assertOk()->assertJsonPath('data.balance', '1000.00');
        $this->getJson("/api/dealer/accounts/{$account->id}/wallet/transactions")->assertOk()->assertJsonPath('data.0.type', 'deposit_credit');
    }

    public function test_admin_wallet_list_global_transactions_and_scoped_history(): void
    {
        [$user, $account, $admin] = $this->fixture();
        $other = DealerAccount::factory()->create(['current_tier_id' => $account->current_tier_id]);
        $wallets = app(DealerWalletService::class);
        $first = $wallets->recordDeposit($account, [
            'operation_key' => (string) Str::uuid(), 'amount' => '1000.00',
            'method' => 'bank_transfer', 'external_reference' => 'ALPHA-001',
        ], $admin);
        $wallets->recordDeposit($other, [
            'operation_key' => (string) Str::uuid(), 'amount' => '500.00',
            'method' => 'cash', 'external_reference' => 'BETA-001',
        ], $admin);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/dealer-wallets')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/dealers')->assertOk()->assertJsonPath('data.0.wallet_balance', '500.00');
        $this->getJson('/api/admin/dealer-wallet-transactions')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.total_balance', '1500.00')
            ->assertJsonPath('summary.total_deposited', '1500.00')
            ->assertJsonPath('summary.total_spent', '0')
            ->assertJsonPath('summary.total_refunded', '0');
        $this->getJson("/api/admin/dealers/{$account->id}/wallet/transactions")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.deposit.external_reference', 'ALPHA-001')
            ->assertJsonPath('data.0.wallet.dealer_account.id', $account->id);
        $this->getJson("/api/admin/dealers/{$account->id}/wallet")
            ->assertOk()->assertJsonPath('data.total_refunded', '0');
        $this->getJson("/api/admin/dealers/{$account->id}/wallet/deposits")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.deposit_code', $first->deposit_code);
        $this->getJson("/api/admin/dealer-wallet-transactions?dealer_id={$other->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.deposit.external_reference', 'BETA-001');
        $this->getJson('/api/admin/dealer-wallet-transactions?search=ALPHA-001')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/dealer-wallet-transactions?search='.urlencode($account->legal_name))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.wallet.dealer_account.id', $account->id);
        $this->getJson('/api/admin/dealer-wallet-transactions?search='.urlencode($other->phone))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.wallet.dealer_account.id', $other->id);
        $this->getJson('/api/admin/dealer-wallet-transactions?type=deposit_credit&from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/dealer-wallet-transactions?from='.now()->addDay()->toDateString())
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/dealer-wallet-transactions?type=order_debit')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/dealer-wallet-transactions?type=adjustment')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/dealer-wallet-transactions?type=unknown')->assertUnprocessable();

        Sanctum::actingAs($user);
        $this->getJson('/api/admin/dealer-wallets')->assertForbidden();
        $this->getJson('/api/admin/dealer-wallet-transactions')->assertForbidden();
    }

    public function test_changed_deposit_replay_and_duplicate_reference_are_rejected(): void
    {
        [, $account, $admin] = $this->fixture();
        Sanctum::actingAs($admin);
        $key = (string) Str::uuid();
        $body = ['operation_key' => $key, 'amount' => '100.00', 'method' => 'bank_transfer', 'external_reference' => 'BANK-002'];
        $this->postJson("/api/admin/dealers/{$account->id}/wallet/deposits", $body)->assertCreated();
        $this->postJson("/api/admin/dealers/{$account->id}/wallet/deposits", [...$body, 'amount' => '101.00'])->assertConflict()->assertJsonPath('code', 'DEALER_WALLET_DEPOSIT_OPERATION_CONFLICT');
        $this->postJson("/api/admin/dealers/{$account->id}/wallet/deposits", [...$body, 'operation_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'WALLET_DEPOSIT_REFERENCE_ALREADY_USED');
    }

    public function test_ensure_does_not_create_opening_money(): void
    {
        [$user, $account] = $this->fixture();
        Sanctum::actingAs($user);
        $this->assertDatabaseMissing('dealer_wallets', ['dealer_account_id' => $account->id]);
        $this->getJson("/api/dealer/accounts/{$account->id}/wallet")
            ->assertOk()->assertJsonPath('data.available_balance', '0.00');
        $wallet = app(DealerWalletService::class)->ensure($account);
        $this->assertSame('0.00', (string) $wallet->balance);
        $this->assertDatabaseCount('dealer_wallet_transactions', 0);
        $this->getJson("/api/dealer/accounts/{$account->id}/wallet/transactions")
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_quick_order_debits_wallet_and_dealer_wallet_refund_credits_once(): void
    {
        [$user, $account, $admin] = $this->fixture();
        $variant = ProductVariant::factory()->create(['sellable_dealer' => true, 'track_inventory' => true]);
        $warehouse = Warehouse::factory()->create(['is_default_sales' => true]);
        $list = PriceList::factory()->create(['pricing_context' => 'dealer', 'scope_type' => 'tier', 'dealer_tier_id' => $account->current_tier_id, 'currency' => 'VND']);
        PriceListItem::factory()->create(['price_list_id' => $list->id, 'product_variant_id' => $variant->id, 'unit_price' => '100.00', 'minimum_quantity' => '2']);
        app(InventoryService::class)->receive(['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => '10', 'operation_key' => (string) Str::uuid()], $admin->id);
        app(DealerWalletService::class)->recordDeposit($account, ['operation_key' => (string) Str::uuid(), 'amount' => '500.00', 'method' => 'other_manual'], $admin);
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/quick-order";
        $items = [['product_variant_id' => $variant->id, 'quantity' => '2']];
        $review = $this->postJson("$url/review", ['items' => $items])->assertJsonPath('data.wallet_sufficient', true)->json('data');
        $body = ['operation_key' => (string) Str::uuid(), 'review_fingerprint' => $review['review_fingerprint'], 'items' => $items, 'recipient_name' => 'Receiver', 'recipient_phone' => '0900000000', 'shipping_address_line1' => 'Street', 'shipping_city' => 'HCM', 'shipping_province' => 'HCM', 'shipping_country' => 'VN'];
        $order = $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.payment_status', 'paid')->json('data');
        $this->assertDatabaseHas('payments', ['payment_method' => 'dealer_wallet', 'status' => 'settled']);
        $this->assertDatabaseHas('payment_allocations', ['allocated_amount' => '200.00']);
        Sanctum::actingAs($admin);
        $refundBody = ['operation_key' => (string) Str::uuid(), 'amount' => '200.00', 'refund_method' => 'dealer_wallet', 'reason' => 'order_cancel'];
        $refund = $this->postJson("/api/admin/sales-orders/{$order['id']}/refunds", $refundBody)->assertCreated()->json('data.refund');
        $this->postJson("/api/admin/sales-orders/{$order['id']}/refunds", $refundBody)->assertCreated()->assertJsonPath('data.refund.id', $refund['id']);
        $this->assertDatabaseCount('dealer_wallet_transactions', 3);
        $this->assertDatabaseHas('dealer_wallet_transactions', ['refund_id' => $refund['id'], 'type' => 'refund_credit']);
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id, 'balance' => '500.00']);
        $this->getJson('/api/admin/dealer-wallet-transactions')->assertOk()
            ->assertJsonPath('summary.total_balance', '500.00')
            ->assertJsonPath('summary.total_deposited', '500.00')
            ->assertJsonPath('summary.total_spent', '200.00')
            ->assertJsonPath('summary.total_refunded', '200.00');
        $this->getJson("/api/admin/dealers/{$account->id}/wallet")
            ->assertOk()->assertJsonPath('data.total_refunded', '200.00');
    }
}
