<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerWalletDepositRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealerWalletDepositRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @return array{User, DealerAccount, User} */
    private function fixture(): array
    {
        $dealer = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create();
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $dealer->id]);

        return [$dealer, $account, User::factory()->admin()->create()];
    }

    private function createRequest(DealerAccount $account, User $dealer, string $amount = '100000'): int
    {
        Sanctum::actingAs($dealer);

        return $this->postJson("/api/dealer/accounts/{$account->id}/wallet/deposit-requests", [
            'amount' => $amount,
            'payment_proof' => $this->proof(),
            'transaction_reference' => 'REF-'.Str::random(12),
            'note' => 'Đã chuyển khoản',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
    }

    private function proof(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }

    public function test_dealer_can_create_and_read_only_own_pending_request_without_credit(): void
    {
        [$dealer, $account, $admin] = $this->fixture();
        $id = $this->createRequest($account, $dealer);
        $request = DealerWalletDepositRequest::query()->findOrFail($id);
        Storage::disk('local')->assertExists($request->payment_proof_path);
        $this->assertDatabaseCount('dealer_wallet_deposits', 0);
        $this->assertDatabaseCount('dealer_wallet_transactions', 0);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id, 'notifiable_type' => User::class]);
        $this->getJson("/api/dealer/accounts/{$account->id}/wallet/deposit-requests")
            ->assertOk()->assertJsonPath('data.0.id', $id);
        $this->get("/api/dealer/accounts/{$account->id}/wallet/deposit-requests/{$id}/proof")->assertOk();
        $this->getJson("/api/dealer/accounts/{$account->id}/wallet")->assertJsonPath('data.balance', '0.00');

        $other = User::factory()->customer()->create();
        $otherAccount = DealerAccount::factory()->create();
        DealerAccountUser::factory()->create(['dealer_account_id' => $otherAccount->id, 'user_id' => $other->id]);
        Sanctum::actingAs($other);
        $this->getJson("/api/dealer/accounts/{$otherAccount->id}/wallet/deposit-requests")->assertOk()->assertJsonPath('data', []);
        $this->getJson("/api/dealer/accounts/{$account->id}/wallet/deposit-requests")->assertNotFound();
        $this->postJson("/api/dealer/accounts/{$account->id}/wallet/deposit-requests", [
            'amount' => '1000', 'payment_proof' => $this->proof(),
        ])->assertNotFound();
        $this->get("/api/dealer/accounts/{$account->id}/wallet/deposit-requests/{$id}/proof")->assertNotFound();
        $this->postJson("/api/admin/dealer-wallet-deposit-requests/{$id}/approve")->assertForbidden();
        Sanctum::actingAs($admin);
        $this->get("/api/admin/dealer-wallet-deposit-requests/{$id}/proof")->assertOk();
    }

    public function test_creation_requires_valid_amount_and_image_and_rejects_control_fields(): void
    {
        [$dealer, $account] = $this->fixture();
        Sanctum::actingAs($dealer);
        $endpoint = "/api/dealer/accounts/{$account->id}/wallet/deposit-requests";
        $this->postJson($endpoint, ['amount' => '1000'])->assertUnprocessable()->assertJsonValidationErrors('payment_proof');
        foreach (['0', '-1', '12.5'] as $amount) {
            $this->postJson($endpoint, ['amount' => $amount, 'payment_proof' => $this->proof()])
                ->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $this->postJson($endpoint, ['amount' => '1000', 'payment_proof' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_proof');
        $this->postJson($endpoint, ['amount' => '1000', 'payment_proof' => $this->proof(), 'status' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('dealer_wallet_deposit_requests', 0);
    }

    public function test_admin_approval_credits_once_and_manual_deposit_still_works(): void
    {
        [$dealer, $account, $admin] = $this->fixture();
        $id = $this->createRequest($account, $dealer);
        Sanctum::actingAs($admin);
        $endpoint = "/api/admin/dealer-wallet-deposit-requests/{$id}";
        $this->getJson('/api/admin/dealer-wallet-deposit-requests?status=pending')->assertOk()
            ->assertJsonPath('pending_count', 1)->assertJsonPath('data.0.id', $id);
        $this->getJson($endpoint)->assertOk()->assertJsonPath('data.wallet_balance', '0.00');
        $this->postJson($endpoint.'/approve', ['amount' => '999999999', 'status' => 'rejected'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.wallet_balance', '100000.00');
        $this->assertDatabaseHas('dealer_wallet_deposit_requests', ['id' => $id, 'status' => 'approved', 'reviewed_by_user_id' => $admin->id]);
        $this->assertDatabaseCount('dealer_wallet_deposits', 1);
        $this->assertDatabaseCount('dealer_wallet_transactions', 1);
        $this->assertNotNull(DealerWalletDepositRequest::query()->findOrFail($id)->dealer_wallet_transaction_id);
        $this->postJson($endpoint.'/approve')->assertConflict()->assertJsonPath('code', 'DEALER_WALLET_DEPOSIT_REQUEST_ALREADY_REVIEWED');
        $this->getJson("/api/admin/dealers/{$account->id}/wallet")->assertJsonPath('data.balance', '100000.00');
        $this->postJson("/api/admin/dealers/{$account->id}/wallet/deposits", [
            'amount' => '5000', 'method' => 'cash', 'operation_key' => (string) Str::uuid(),
        ])->assertCreated();
        $this->getJson("/api/admin/dealers/{$account->id}/wallet")->assertJsonPath('data.balance', '105000.00');
    }

    public function test_rejection_requires_reason_and_does_not_change_wallet(): void
    {
        [$dealer, $account, $admin] = $this->fixture();
        $id = $this->createRequest($account, $dealer);
        Sanctum::actingAs($admin);
        $endpoint = "/api/admin/dealer-wallet-deposit-requests/{$id}";
        $this->postJson($endpoint.'/reject')->assertUnprocessable()->assertJsonValidationErrors('rejection_reason');
        $this->postJson($endpoint.'/reject', ['rejection_reason' => 'Không thấy giao dịch'])
            ->assertOk()->assertJsonPath('data.rejection_reason', 'Không thấy giao dịch');
        $this->postJson($endpoint.'/approve')->assertConflict();
        $this->assertDatabaseCount('dealer_wallet_deposits', 0);
        $this->getJson("/api/admin/dealers/{$account->id}/wallet")->assertJsonPath('data.balance', '0.00');
        Sanctum::actingAs($dealer);
        $this->getJson("/api/dealer/accounts/{$account->id}/wallet/deposit-requests")
            ->assertJsonPath('data.0.rejection_reason', 'Không thấy giao dịch');
    }

    public function test_new_payos_checkout_is_disabled(): void
    {
        [$dealer, $account] = $this->fixture();
        Sanctum::actingAs($dealer);
        $this->postJson("/api/dealer/accounts/{$account->id}/wallet/top-ups", [
            'amount' => 2000, 'operation_key' => (string) Str::uuid(),
        ])->assertStatus(410);

        $this->assertDatabaseCount('dealer_wallet_top_up_requests', 0);
    }
}
