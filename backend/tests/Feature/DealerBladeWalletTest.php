<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerWalletDepositRequest;
use App\Models\User;
use App\Services\DealerWalletService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DealerBladeWalletTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function proof(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }

    /** @return array{User, DealerAccount} */
    private function dealer(): array
    {
        $user = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create();
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);

        return [$user, $account];
    }

    public function test_wallet_requires_active_primary_dealer_membership(): void
    {
        $this->withoutVite();
        $this->get('/dealer/wallet')->assertRedirect('/login');
        $customer = User::factory()->customer()->create();
        $this->actingAs($customer);
        $this->get('/dealer/wallet')->assertForbidden();
        $this->post('/dealer/wallet/deposit-requests', ['amount' => '100'])->assertForbidden();
        $account = DealerAccount::factory()->create();
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $customer->id]);
        $this->get('/dealer/wallet')->assertOk()->assertSee($account->code);
        $account->update(['status' => 'suspended']);
        $this->get('/dealer/wallet')->assertForbidden();
        $this->post('/dealer/wallet/deposit-requests', ['amount' => '100'])->assertForbidden();
    }

    public function test_dealer_creates_pending_request_with_private_proof_and_no_wallet_credit(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        [$user, $account] = $this->dealer();
        $this->actingAs($user);

        $this->post('/dealer/wallet/deposit-requests', [
            'amount' => '50000', 'payment_proof' => $this->proof(),
            'transaction_reference' => 'WEB-DEALER-001', 'note' => '<script>alert(1)</script>',
        ])->assertRedirect();
        $record = DealerWalletDepositRequest::query()->firstOrFail();
        $this->assertSame('pending', $record->status);
        $this->assertSame($account->id, $record->dealer_account_id);
        Storage::disk('local')->assertExists($record->payment_proof_path);
        $this->assertDatabaseCount('dealer_wallet_deposits', 0);
        $this->assertDatabaseCount('dealer_wallet_transactions', 0);
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id, 'balance' => '0.00']);
        $this->get('/dealer/wallet/deposit-requests/'.$record->id)->assertOk()
            ->assertSee($record->request_code)->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee($record->payment_proof_path);
        $this->get('/dealer/wallet/deposit-requests/'.$record->id.'/proof')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/dealer/wallet')->assertOk()->assertSee('Chờ xác nhận')->assertSee('0 ₫');
    }

    public function test_deposit_form_rejects_invalid_amount_proof_and_control_fields(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        [$user] = $this->dealer();
        $this->actingAs($user);
        $url = '/dealer/wallet/deposit-requests';

        $this->from('/dealer/wallet')->post($url, ['amount' => '0', 'payment_proof' => $this->proof()])
            ->assertSessionHasErrors('amount');
        $this->from('/dealer/wallet')->post($url, ['amount' => '1000',
            'payment_proof' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors('payment_proof');
        $this->from('/dealer/wallet')->post($url, ['amount' => '1000', 'payment_proof' => $this->proof(),
            'status' => 'approved', 'dealer_account_id' => 999])->assertSessionHasErrors(['status', 'dealer_account_id']);
        $this->assertDatabaseCount('dealer_wallet_deposit_requests', 0);
        $this->assertDatabaseCount('dealer_wallet_transactions', 0);
    }

    public function test_request_is_isolated_until_user_has_active_primary_membership(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        [$owner, $account] = $this->dealer();
        $this->actingAs($owner);
        $this->post('/dealer/wallet/deposit-requests', ['amount' => '25000', 'payment_proof' => $this->proof()])->assertRedirect();
        $record = DealerWalletDepositRequest::query()->firstOrFail();
        [$other, $otherAccount] = $this->dealer();
        $this->actingAs($other);

        $this->get('/dealer/wallet')->assertOk()->assertSee($otherAccount->code)->assertDontSee($account->code);
        $this->get('/dealer/wallet/deposit-requests/'.$record->id)->assertNotFound();
        $this->get('/dealer/wallet/deposit-requests/'.$record->id.'/proof')->assertNotFound();
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $other->id]);
        $this->get('/dealer/wallet')->assertOk()->assertSee($account->code);
        $this->get('/dealer/wallet/deposit-requests/'.$record->id)->assertOk()->assertSee($record->request_code);
    }

    public function test_dealer_sees_persisted_credit_and_transaction_after_admin_recording(): void
    {
        $this->withoutVite();
        [$user, $account] = $this->dealer();
        $admin = User::factory()->admin()->create();
        app(DealerWalletService::class)->recordDeposit($account, [
            'operation_key' => (string) Str::uuid(), 'amount' => '75000', 'method' => 'cash',
        ], $admin);
        $this->actingAs($user);

        $this->get('/dealer/wallet')->assertOk()->assertSee('75.000 ₫')->assertSee('Nạp tiền');
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id, 'balance' => '75000.00']);
        $this->assertDatabaseCount('dealer_wallet_transactions', 1);
    }
}
