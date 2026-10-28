<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\User;
use App\Services\DealerWalletDepositRequestService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBladeDealerWalletTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function proof(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }

    public function test_admin_routes_reject_guests_and_customers(): void
    {
        $this->withoutVite();
        $account = DealerAccount::factory()->create();
        $this->get('/admin/dealer-wallets')->assertRedirect('/login');
        $this->get('/admin/dealer-wallet-deposit-requests')->assertRedirect('/login');
        $this->post('/admin/dealer-wallets/'.$account->id.'/deposits')->assertRedirect('/login');

        $this->actingAs(User::factory()->customer()->create());
        $this->get('/admin/dealer-wallets')->assertForbidden();
        $this->get('/admin/dealer-wallet-deposit-requests')->assertForbidden();
        $this->post('/admin/dealer-wallets/'.$account->id.'/deposits', ['amount' => '1000'])->assertForbidden();
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/dealer-wallets/999999')->assertNotFound();
        $this->get('/admin/dealer-wallet-deposit-requests/999999')->assertNotFound();
    }

    public function test_wallet_overview_filters_and_manual_credit_replays_without_duplicate_ledger(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $account = DealerAccount::factory()->create(['legal_name' => 'Junie Wallet']);
        $other = DealerAccount::factory()->create(['legal_name' => 'Other Wallet']);
        $this->actingAs($admin);

        $this->get('/admin/dealer-wallets?search=Junie%20Wallet')->assertOk()->assertSee('Junie Wallet')->assertDontSee('Other Wallet');
        $this->get('/admin/dealer-wallets/'.$account->id)->assertOk()->assertSee('0 ₫');
        $key = (string) Str::uuid();
        $url = '/admin/dealer-wallets/'.$account->id.'/deposits';
        $body = ['operation_key' => $key, 'amount' => '125000.00', 'method' => 'bank_transfer',
            'external_reference' => 'WEB-WALLET-001', 'note' => 'Received'];
        $this->post($url, $body)->assertRedirect(route('admin.web.dealer-wallets.show', $account));
        $this->post($url, $body)->assertRedirect();
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id, 'balance' => '125000.00']);
        $this->assertDatabaseCount('dealer_wallet_deposits', 1);
        $this->assertDatabaseCount('dealer_wallet_transactions', 1);
        $this->assertDatabaseHas('dealer_wallet_deposits', ['dealer_account_id' => $account->id,
            'recorded_by_user_id' => $admin->id, 'operation_key' => $key]);
        $this->get('/admin/dealer-wallet-transactions?dealer_id='.$account->id.'&type=deposit_credit')
            ->assertOk()->assertSee('WEB-WALLET-001');
        $this->get('/admin/dealer-wallets/'.$other->id)->assertOk()->assertSee('0 ₫');
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $other->id, 'balance' => '0.00']);
    }

    public function test_manual_credit_rejects_invalid_amount_inactive_account_and_changed_replay(): void
    {
        $this->withoutVite();
        $account = DealerAccount::factory()->create();
        $other = DealerAccount::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $url = '/admin/dealer-wallets/'.$account->id.'/deposits';
        $key = (string) Str::uuid();
        $body = ['operation_key' => $key, 'amount' => '1000', 'method' => 'cash'];

        $this->from('/admin/dealer-wallets/'.$account->id)->post($url, [...$body, 'amount' => '0'])
            ->assertSessionHasErrors('operation');
        $this->post($url, $body)->assertRedirect();
        $this->from('/admin/dealer-wallets/'.$account->id)->post($url, [...$body, 'amount' => '2000'])
            ->assertSessionHasErrors('operation');
        $this->from('/admin/dealer-wallets/'.$account->id)->post($url, [...$body,
            'operation_key' => (string) Str::uuid(), 'balance' => '999999'])->assertSessionHasErrors('balance');
        $this->from('/admin/dealer-wallets/'.$other->id)->post('/admin/dealer-wallets/'.$other->id.'/deposits', $body)
            ->assertSessionHasErrors('operation');
        $this->post('/admin/dealer-wallets/999999/deposits', $body)->assertNotFound();
        $account->update(['status' => 'suspended']);
        $this->from('/admin/dealer-wallets/'.$account->id)->post($url, [...$body, 'operation_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('dealer_wallet_transactions', 1);
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id, 'balance' => '1000.00']);
        $this->assertDatabaseMissing('dealer_wallets', ['dealer_account_id' => $other->id]);
    }

    public function test_admin_reviews_private_proof_and_approval_credits_exactly_once(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $dealer = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create();
        $depositRequest = app(DealerWalletDepositRequestService::class)->create($account, $dealer, [
            'amount' => 80000, 'payment_proof' => $this->proof(), 'transaction_reference' => 'WEB-PROOF-001',
        ]);
        $this->actingAs($admin);

        $this->get('/admin/dealer-wallet-deposit-requests?status=pending&dealer_id='.$account->id)
            ->assertOk()->assertSee($depositRequest->request_code);
        $this->get('/admin/dealer-wallet-deposit-requests/'.$depositRequest->id)->assertOk()
            ->assertSee('WEB-PROOF-001')->assertDontSee($depositRequest->payment_proof_path);
        $this->get('/admin/dealer-wallet-deposit-requests/'.$depositRequest->id.'/proof')->assertOk();
        $this->post('/admin/dealer-wallet-deposit-requests/'.$depositRequest->id.'/approve')->assertRedirect();
        $this->from('/admin/dealer-wallet-deposit-requests/'.$depositRequest->id)
            ->post('/admin/dealer-wallet-deposit-requests/'.$depositRequest->id.'/approve')->assertSessionHasErrors('operation');
        $this->assertSame('approved', $depositRequest->fresh()->status);
        $this->assertSame($admin->id, $depositRequest->fresh()->reviewed_by_user_id);
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id, 'balance' => '80000.00']);
        $this->assertDatabaseCount('dealer_wallet_deposits', 1);
        $this->assertDatabaseCount('dealer_wallet_transactions', 1);
        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_rejection_requires_reason_and_does_not_credit_or_delete_proof(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $dealer = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create();
        $depositRequest = app(DealerWalletDepositRequestService::class)->create($account, $dealer, [
            'amount' => 30000, 'payment_proof' => $this->proof(),
        ]);
        $this->actingAs($admin);
        $url = '/admin/dealer-wallet-deposit-requests/'.$depositRequest->id;

        $this->from($url)->post($url.'/reject')->assertSessionHasErrors('rejection_reason');
        $this->post($url.'/reject', ['rejection_reason' => 'Không thấy tiền vào tài khoản'])->assertRedirect();
        $this->from($url)->post($url.'/approve')->assertSessionHasErrors('operation');
        $this->assertSame('rejected', $depositRequest->fresh()->status);
        Storage::disk('local')->assertExists($depositRequest->payment_proof_path);
        $this->assertDatabaseCount('dealer_wallet_deposits', 0);
        $this->assertDatabaseCount('dealer_wallet_transactions', 0);
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id, 'balance' => '0.00']);
    }
}
