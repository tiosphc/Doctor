<?php

namespace Tests\Feature;

use App\Models\AdministrativeWard;
use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerShippingAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DealerShippingAddressApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{User, DealerAccount, array<string, mixed>} */
    private function fixture(): array
    {
        $user = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create();
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $ward = AdministrativeWard::query()->where('province_code', '79')->firstOrFail();

        return [$user, $account, [
            'recipient_name' => 'Người nhận',
            'recipient_phone' => '0900000000',
            'address_line' => '1 Main Street',
            'province_code' => '79',
            'ward_code' => $ward->code,
            'postal_code' => '70000',
            'is_default' => false,
        ]];
    }

    public function test_address_api_requires_active_membership_and_hides_other_accounts(): void
    {
        [$user, $account, $body] = $this->fixture();
        $otherAccount = DealerAccount::factory()->create();
        $otherAddress = DealerShippingAddress::query()->create([
            ...$body, 'dealer_account_id' => $otherAccount->id, 'is_default' => true,
        ]);
        $base = "/api/dealer/accounts/{$account->id}/shipping-addresses";

        $this->getJson($base)->assertUnauthorized();
        $this->postJson($base, $body)->assertUnauthorized();
        $this->actingAs(User::factory()->customer()->create());
        $this->getJson($base)->assertNotFound();
        $this->actingAs($user);
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'data');
        $this->putJson($base.'/'.$otherAddress->id, $body)->assertNotFound();
        $this->deleteJson($base.'/'.$otherAddress->id)->assertNotFound();
        $this->assertSame($otherAccount->id, $otherAddress->fresh()->dealer_account_id);

        $account->update(['status' => DealerAccount::STATUS_SUSPENDED]);
        $this->getJson($base)->assertNotFound();
    }

    public function test_address_api_creates_edits_default_and_deletes_without_commerce_writes(): void
    {
        [$user, $account, $body] = $this->fixture();
        $this->actingAs($user);
        $base = "/api/dealer/accounts/{$account->id}/shipping-addresses";

        $firstId = $this->postJson($base, $body)->assertCreated()
            ->assertJsonPath('data.is_default', true)->json('data.id');
        $secondId = $this->postJson($base, [...$body, 'recipient_name' => 'Người nhận thứ hai', 'is_default' => true])
            ->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');
        $this->assertFalse(DealerShippingAddress::query()->findOrFail($firstId)->is_default);
        $this->getJson($base)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondId);

        $this->putJson($base.'/'.$firstId, [...$body, 'recipient_name' => 'Người nhận đã sửa', 'is_default' => true])
            ->assertOk()->assertJsonPath('data.recipient_name', 'Người nhận đã sửa');
        $this->assertTrue(DealerShippingAddress::query()->findOrFail($firstId)->is_default);
        $this->assertFalse(DealerShippingAddress::query()->findOrFail($secondId)->is_default);
        $this->deleteJson($base.'/'.$secondId)->assertOk();
        $this->assertDatabaseMissing('dealer_shipping_addresses', ['id' => $secondId]);
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('dealer_wallet_transactions', 0);
    }

    public function test_invalid_address_fields_do_not_write(): void
    {
        [$user, $account, $body] = $this->fixture();
        $this->actingAs($user);
        $base = "/api/dealer/accounts/{$account->id}/shipping-addresses";
        $otherWard = AdministrativeWard::query()->where('province_code', '!=', '79')->firstOrFail();

        $this->postJson($base, [...$body, 'recipient_phone' => 'letters'])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient_phone');
        $this->postJson($base, [...$body, 'province_code' => 'missing'])
            ->assertUnprocessable()->assertJsonValidationErrors('province_code');
        $this->postJson($base, [...$body, 'ward_code' => $otherWard->code])
            ->assertUnprocessable()->assertJsonValidationErrors('ward_code');
        $this->postJson($base, [...$body, 'recipient_name' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient_name');
        $this->assertDatabaseCount('dealer_shipping_addresses', 0);
    }
}
