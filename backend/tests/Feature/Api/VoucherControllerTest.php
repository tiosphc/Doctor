<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VoucherControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_customer_wallet_returns_only_owned_vouchers_with_effective_expiry_status(): void
    {
        $customer = User::factory()->customer()->create();
        $active = Voucher::factory()->for($customer)->create(['expires_at' => now()->addDays(10)]);
        $expired = Voucher::factory()->for($customer)->create(['expires_at' => now()->subMinute()]);
        Voucher::factory()->create();
        Sanctum::actingAs($customer);

        $this->getJson('/api/my-vouchers')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/my-vouchers?status=active')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->getJson('/api/my-vouchers?status=expired')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $expired->id)
            ->assertJsonPath('data.0.status', Voucher::STATUS_EXPIRED);
    }

    public function test_customer_can_request_more_active_vouchers_for_booking_selection(): void
    {
        $customer = User::factory()->customer()->create();
        Voucher::factory()->count(12)->for($customer)->create();
        Sanctum::actingAs($customer);

        $this->getJson('/api/my-vouchers?status=active&per_page=100')
            ->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.per_page', 100);

        $this->getJson('/api/my-vouchers?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_customer_can_resolve_an_owned_active_voucher_by_code(): void
    {
        $customer = User::factory()->customer()->create();
        $voucher = Voucher::factory()->for($customer)->create(['code' => 'RVW-ABCD-EFGH']);
        Sanctum::actingAs($customer);

        $this->postJson('/api/my-vouchers/resolve', ['code' => '  rvw-abcd-efgh  '])
            ->assertOk()
            ->assertJsonPath('data.id', $voucher->id)
            ->assertJsonPath('data.code', 'RVW-ABCD-EFGH');
    }

    public function test_customer_can_resolve_a_general_admin_voucher_by_code(): void
    {
        $customer = User::factory()->customer()->create();
        $voucher = Voucher::factory()->admin()->create(['code' => 'JUN-WELCOME']);
        Sanctum::actingAs($customer);

        $this->postJson('/api/my-vouchers/resolve', ['code' => ' jun-welcome '])
            ->assertOk()
            ->assertJsonPath('data.id', $voucher->id)
            ->assertJsonPath('data.source', Voucher::SOURCE_ADMIN)
            ->assertJsonPath('data.customer', null);
    }

    public function test_customer_cannot_resolve_another_customers_voucher_code(): void
    {
        $customer = User::factory()->customer()->create();
        $otherVoucher = Voucher::factory()->create(['code' => 'RVW-OTHER-USER']);
        Sanctum::actingAs($customer);

        $this->postJson('/api/my-vouchers/resolve', ['code' => $otherVoucher->code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_customer_cannot_resolve_an_unavailable_voucher_code(): void
    {
        $customer = User::factory()->customer()->create();
        $expiredVoucher = Voucher::factory()->for($customer)->create([
            'code' => 'RVW-EXPIRED-01',
            'expires_at' => now()->subMinute(),
        ]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/my-vouchers/resolve', ['code' => $expiredVoucher->code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_guest_cannot_resolve_a_voucher_code(): void
    {
        $this->postJson('/api/my-vouchers/resolve', ['code' => 'RVW-ABCD-EFGH'])
            ->assertUnauthorized();
    }
}
