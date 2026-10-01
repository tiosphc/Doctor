<?php

namespace Tests\Feature;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerWalletTopUpRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealerWalletTopUpTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $lookupResponses = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.payos.client_id', 'test-client');
        config()->set('services.payos.api_key', 'test-api-key');
        config()->set('services.payos.checksum_key', 'test-checksum');
        config()->set('services.payos.frontend_url', 'https://shop.example.test');
        Http::preventStrayRequests();
        Http::fake(['api-merchant.payos.vn/*' => function ($request) {
            if ($request->method() === 'GET') {
                $orderCode = (int) basename($request->url());
                $data = $this->lookupResponses[$orderCode] ?? null;

                return $data === null ? Http::response(['code' => '01'], 404) : Http::response($this->signedResponse($data));
            }
            $body = $request->data();
            $signed = 'amount='.$body['amount'].'&cancelUrl='.$body['cancelUrl'].'&description='.$body['description'].'&orderCode='.$body['orderCode'].'&returnUrl='.$body['returnUrl'];
            $this->assertSame(hash_hmac('sha256', $signed, 'test-checksum'), $body['signature']);

            return Http::response($this->signedResponse([
                'orderCode' => $body['orderCode'], 'amount' => $body['amount'], 'currency' => 'VND',
                'paymentLinkId' => 'link-'.$body['orderCode'], 'status' => 'PENDING',
                'checkoutUrl' => 'https://pay.payos.vn/web/'.$body['orderCode'],
            ]));
        }]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function signedResponse(array $data): array
    {
        ksort($data);
        $signature = hash_hmac('sha256', implode('&', array_map(fn ($key) => $key.'='.(is_array($data[$key]) ? json_encode($data[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($data[$key] ?? '')), array_keys($data))), 'test-checksum');

        return ['code' => '00', 'data' => $data, 'signature' => $signature];
    }

    /** @return array{User, DealerAccount} */
    private function dealer(): array
    {
        $user = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create();
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);

        return [$user, $account];
    }

    private function providerStatus(DealerWalletTopUpRequest $topUp, string $status, array $changes = []): void
    {
        $paid = $status === 'PAID';
        $this->lookupResponses[(int) $topUp->provider_order_code] = array_replace([
            'id' => 'link-'.$topUp->provider_order_code,
            'orderCode' => (int) $topUp->provider_order_code,
            'amount' => (int) $topUp->amount,
            'amountPaid' => $paid ? (int) $topUp->amount : 0,
            'amountRemaining' => $paid ? 0 : (int) $topUp->amount,
            'status' => $status,
            'transactions' => $paid ? [[
                'amount' => (int) $topUp->amount,
                'reference' => 'BANK-'.$topUp->provider_order_code,
                'transactionDateTime' => '2026-09-26 12:00:00',
            ]] : [],
        ], $changes);
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function webhook(DealerWalletTopUpRequest $topUp, array $changes = [], bool $validSignature = true): array
    {
        $data = array_replace([
            'orderCode' => (int) $topUp->provider_order_code,
            'amount' => (int) $topUp->amount,
            'currency' => 'VND',
            'paymentLinkId' => 'link-'.$topUp->provider_order_code,
            'reference' => 'BANK-'.$topUp->provider_order_code,
            'code' => '00',
            'desc' => 'Success',
        ], $changes);
        ksort($data);
        $signature = hash_hmac('sha256', implode('&', array_map(fn ($key) => $key.'='.$data[$key], array_keys($data))), 'test-checksum');

        return ['code' => '00', 'success' => true, 'data' => $data, 'signature' => $validSignature ? $signature : str_repeat('0', 64)];
    }

    public function test_only_valid_paid_webhook_credits_one_hundred_million_once(): void
    {
        [$user, $account] = $this->dealer();
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/wallet";
        $key = (string) Str::uuid();
        $topUp = $this->postJson("$url/top-ups", ['amount' => 100000000, 'operation_key' => $key])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertSame('https://pay.payos.vn/web/'.(100000000000 + $topUp['id']), $topUp['checkout_url']);
        $this->getJson($url)->assertJsonPath('data.balance', '0.00');
        $this->getJson("$url/top-ups/{$topUp['id']}")->assertJsonPath('data.status', 'pending');
        $this->getJson($url)->assertJsonPath('data.balance', '0.00');
        $this->postJson("$url/top-ups", ['amount' => 100000000, 'operation_key' => $key])->assertCreated()->assertJsonPath('data.id', $topUp['id']);
        Http::assertSentCount(1);

        $model = DealerWalletTopUpRequest::query()->findOrFail($topUp['id']);
        $this->postJson('/api/webhooks/payos', $this->webhook($model, ['code' => '01']))->assertOk();
        $this->getJson($url)->assertJsonPath('data.balance', '0.00');
        $this->assertDatabaseCount('dealer_wallet_deposits', 0);

        $paid = $this->webhook($model);
        $this->postJson('/api/webhooks/payos', $paid)->assertOk();
        $this->postJson('/api/webhooks/payos', $paid)->assertOk();
        $this->getJson($url)->assertJsonPath('data.balance', '100000000.00');
        $this->getJson("$url/top-ups/{$topUp['id']}")->assertJsonPath('data.status', 'paid');
        $this->assertDatabaseCount('dealer_wallet_deposits', 1);
        $this->assertDatabaseHas('dealer_wallet_deposits', ['amount' => '100000000.00', 'method' => 'payos', 'dealer_wallet_top_up_request_id' => $topUp['id']]);
        $this->assertDatabaseCount('dealer_wallet_transactions', 1);
        $this->assertDatabaseHas('dealer_wallet_transactions', ['amount' => '100000000.00', 'direction' => 'credit', 'type' => 'deposit_credit']);
    }

    public function test_invalid_or_mismatched_webhooks_never_credit(): void
    {
        [$user, $account] = $this->dealer();
        Sanctum::actingAs($user);
        $id = $this->postJson("/api/dealer/accounts/{$account->id}/wallet/top-ups", ['amount' => 100000000, 'operation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $model = DealerWalletTopUpRequest::query()->findOrFail($id);
        $this->postJson('/api/webhooks/payos', $this->webhook($model, [], false))->assertUnauthorized();
        $this->postJson('/api/webhooks/payos', $this->webhook($model, ['amount' => 99999999]))->assertConflict();
        $this->postJson('/api/webhooks/payos', $this->webhook($model, ['currency' => 'USD']))->assertConflict();
        $this->postJson('/api/webhooks/payos', $this->webhook($model, ['paymentLinkId' => 'wrong']))->assertConflict();
        $this->assertDatabaseCount('dealer_wallet_deposits', 0);
        $this->getJson("/api/dealer/accounts/{$account->id}/wallet")->assertJsonPath('data.balance', '0.00');
    }

    public function test_other_dealer_cannot_read_top_up_and_conflicting_replay_is_rejected(): void
    {
        [$user, $account] = $this->dealer();
        [, $other] = $this->dealer();
        Sanctum::actingAs($user);
        $key = (string) Str::uuid();
        $id = $this->postJson("/api/dealer/accounts/{$account->id}/wallet/top-ups", ['amount' => 100000000, 'operation_key' => $key])->assertCreated()->json('data.id');
        $this->postJson("/api/dealer/accounts/{$account->id}/wallet/top-ups", ['amount' => 90000000, 'operation_key' => $key])->assertConflict();
        $this->getJson("/api/dealer/accounts/{$other->id}/wallet/top-ups/{$id}")->assertNotFound();
    }

    public function test_unconfigured_provider_never_creates_request_or_credit(): void
    {
        [$user, $account] = $this->dealer();
        config()->set('services.payos.api_key', null);
        Sanctum::actingAs($user);
        $this->postJson("/api/dealer/accounts/{$account->id}/wallet/top-ups", [
            'amount' => 100000000,
            'operation_key' => (string) Str::uuid(),
        ])->assertStatus(503);
        $this->assertDatabaseCount('dealer_wallet_top_up_requests', 0);
        $this->assertDatabaseCount('dealer_wallet_deposits', 0);
    }

    public function test_trusted_requery_completes_once_and_stale_events_cannot_regress_paid(): void
    {
        [$user, $account] = $this->dealer();
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/wallet";
        $id = $this->postJson("$url/top-ups", ['amount' => 100000000, 'operation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $topUp = DealerWalletTopUpRequest::findOrFail($id);
        $this->providerStatus($topUp, 'PENDING');
        $this->postJson("$url/top-ups/$id/refresh")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->getJson($url)->assertJsonPath('data.balance', '0.00');
        $this->providerStatus($topUp, 'PAID');
        $this->postJson("$url/top-ups/$id/refresh")->assertOk()->assertJsonPath('data.status', 'paid');
        $this->postJson("$url/top-ups/$id/refresh")->assertOk()->assertJsonPath('data.status', 'paid');
        $this->postJson('/api/webhooks/payos', $this->webhook($topUp, ['code' => '01']))->assertOk();
        $this->getJson($url)->assertJsonPath('data.balance', '100000000.00');
        $this->assertDatabaseCount('dealer_wallet_deposits', 1);
        $this->assertDatabaseCount('dealer_wallet_transactions', 1);
    }

    public function test_requery_tracks_terminal_status_and_later_verified_payment_wins(): void
    {
        [$user, $account] = $this->dealer();
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/wallet";
        $id = $this->postJson("$url/top-ups", ['amount' => 100000000, 'operation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $topUp = DealerWalletTopUpRequest::findOrFail($id);
        $this->providerStatus($topUp, 'EXPIRED');
        $this->postJson("$url/top-ups/$id/refresh")->assertJsonPath('data.status', 'expired');
        $this->providerStatus($topUp, 'PENDING');
        $this->postJson("$url/top-ups/$id/refresh")->assertJsonPath('data.status', 'expired');
        $this->providerStatus($topUp, 'PAID');
        $this->postJson("$url/top-ups/$id/refresh")->assertJsonPath('data.status', 'paid');
        $this->assertDatabaseCount('dealer_wallet_deposits', 1);
        $this->assertDatabaseHas('dealer_wallets', ['dealer_account_id' => $account->id, 'balance' => '100000000.00']);
    }

    public function test_failed_and_cancelled_provider_states_never_credit_wallet(): void
    {
        [$user, $account] = $this->dealer();
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/wallet";
        foreach (['FAILED' => 'failed', 'CANCELLED' => 'cancelled'] as $providerStatus => $localStatus) {
            $id = $this->postJson("$url/top-ups", ['amount' => 100000000, 'operation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
            $topUp = DealerWalletTopUpRequest::findOrFail($id);
            $this->providerStatus($topUp, $providerStatus);
            $this->postJson("$url/top-ups/$id/refresh")->assertOk()->assertJsonPath('data.status', $localStatus);
            $this->postJson('/api/webhooks/payos', $this->webhook($topUp, ['code' => '01']))->assertOk();
        }
        $this->getJson($url)->assertJsonPath('data.balance', '0.00');
        $this->assertDatabaseCount('dealer_wallet_deposits', 0);
    }

    public function test_requery_rejects_unsigned_or_mismatched_provider_result_without_financial_write(): void
    {
        [$user, $account] = $this->dealer();
        Sanctum::actingAs($user);
        $url = "/api/dealer/accounts/{$account->id}/wallet";
        $id = $this->postJson("$url/top-ups", ['amount' => 100000000, 'operation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $topUp = DealerWalletTopUpRequest::findOrFail($id);
        $this->providerStatus($topUp, 'PAID', ['id' => 'wrong-link']);
        $this->postJson("$url/top-ups/$id/refresh")->assertConflict();
        $this->providerStatus($topUp, 'PAID', ['amountPaid' => 99999999]);
        $this->postJson("$url/top-ups/$id/refresh")->assertStatus(502);
        $this->getJson($url)->assertJsonPath('data.balance', '0.00');
        $this->assertDatabaseCount('dealer_wallet_deposits', 0);
    }

    public function test_reconciliation_reports_drift_and_apply_uses_trusted_completion(): void
    {
        [$user, $account] = $this->dealer();
        Sanctum::actingAs($user);
        $id = $this->postJson("/api/dealer/accounts/{$account->id}/wallet/top-ups", ['amount' => 100000000, 'operation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $topUp = DealerWalletTopUpRequest::findOrFail($id);
        $this->providerStatus($topUp, 'PAID');
        $this->artisan('dealer-wallet-topups:reconcile', ['--dry-run' => true])->assertExitCode(1);
        $this->assertDatabaseCount('dealer_wallet_deposits', 0);
        $this->artisan('dealer-wallet-topups:reconcile', ['--apply' => true])->assertExitCode(0);
        $this->artisan('dealer-wallet-topups:reconcile', ['--dry-run' => true])->assertExitCode(0);
        $this->assertDatabaseCount('dealer_wallet_deposits', 1);
    }

    public function test_reconciliation_reports_broken_linkage_without_inventing_credit(): void
    {
        [$user, $account] = $this->dealer();
        Sanctum::actingAs($user);
        $id = $this->postJson("/api/dealer/accounts/{$account->id}/wallet/top-ups", ['amount' => 100000000, 'operation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $topUp = DealerWalletTopUpRequest::findOrFail($id);
        $this->postJson('/api/webhooks/payos', $this->webhook($topUp))->assertOk();
        $topUp->update(['provider_reference' => 'DIFFERENT-REFERENCE']);
        $this->artisan('dealer-wallet-topups:reconcile', ['--dry-run' => true])->assertExitCode(1);
        $this->artisan('dealer-wallet-topups:reconcile', ['--apply' => true])->assertExitCode(1);
        $this->assertDatabaseCount('dealer_wallet_transactions', 1);
    }

    public function test_admin_can_monitor_topups_but_dealer_cannot(): void
    {
        [$user, $account] = $this->dealer();
        Sanctum::actingAs($user);
        $this->postJson("/api/dealer/accounts/{$account->id}/wallet/top-ups", ['amount' => 100000000, 'operation_key' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/admin/dealer-wallet-top-ups')->assertForbidden();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/admin/dealer-wallet-top-ups')->assertOk()
            ->assertJsonPath('data.0.dealer_account.id', $account->id)
            ->assertJsonPath('data.0.amount', '100000000.00')
            ->assertJsonPath('data.0.provider', 'payos');
        $this->getJson('/api/admin/dealer-wallet-top-ups?status=pending&search='.urlencode($account->phone))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/dealer-wallet-top-ups?from='.now()->addDay()->toDateString())
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/dealer-wallet-top-ups?to='.now()->subDay()->toDateString())
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
