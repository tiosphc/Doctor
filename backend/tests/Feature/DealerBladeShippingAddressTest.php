<?php

namespace Tests\Feature;

use App\Models\AdministrativeWard;
use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerShippingAddress;
use App\Models\DealerTier;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DealerBladeShippingAddressTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{User, DealerAccount, array<string, mixed>} */
    private function fixture(): array
    {
        $user = User::factory()->customer()->create();
        $account = DealerAccount::factory()->create(['current_tier_id' => DealerTier::factory()->create()->id]);
        DealerAccountUser::factory()->create(['dealer_account_id' => $account->id, 'user_id' => $user->id]);
        $ward = AdministrativeWard::query()->where('province_code', '79')->firstOrFail();
        $body = ['recipient_name' => 'Người nhận', 'recipient_phone' => '0900000000',
            'address_line' => '1 Main Street', 'province_code' => '79', 'ward_code' => $ward->code,
            'postal_code' => '70000', 'is_default' => '0'];

        return [$user, $account, $body];
    }

    public function test_address_routes_require_active_dealer_and_correct_owner(): void
    {
        [$user, $account, $body] = $this->fixture();
        $otherAccount = DealerAccount::factory()->create();
        $other = DealerShippingAddress::query()->create([...$body,
            'dealer_account_id' => $otherAccount->id, 'is_default' => true]);
        $this->get(route('dealer.web.addresses.index'))->assertRedirect(route('login'));
        $this->post(route('dealer.web.addresses.store'), $body)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->customer()->create());
        $this->get(route('dealer.web.addresses.index'))->assertForbidden();
        $this->post(route('dealer.web.addresses.store'), $body)->assertForbidden();

        $this->actingAs($user);
        $this->get(route('dealer.web.addresses.index'))->assertOk()->assertDontSee($other->recipient_name);
        $this->get(route('dealer.web.addresses.edit', $other->id))->assertNotFound();
        $this->put(route('dealer.web.addresses.update', $other->id), $body)->assertNotFound();
        $this->delete(route('dealer.web.addresses.destroy', $other->id))->assertNotFound();
        $this->get(route('dealer.web.addresses.edit', 999999))->assertNotFound();
        $this->assertSame($otherAccount->id, $other->fresh()->dealer_account_id);
        $account->update(['status' => 'suspended']);
        $this->get(route('dealer.web.addresses.index'))->assertForbidden();
    }

    public function test_create_edit_default_and_delete_follow_existing_address_contract(): void
    {
        [$user, $account, $body] = $this->fixture();
        $this->actingAs($user);
        $this->get(route('dealer.web.addresses.create'))->assertOk()->assertSee('Tỉnh / thành');
        $this->post(route('dealer.web.addresses.store'), $body)
            ->assertRedirect(route('dealer.web.addresses.index'));
        $first = DealerShippingAddress::query()->sole();
        $this->assertTrue($first->is_default);
        $this->assertSame($account->id, $first->dealer_account_id);

        $this->post(route('dealer.web.addresses.store'), [...$body,
            'recipient_name' => 'Người nhận thứ hai', 'is_default' => '1'])->assertRedirect();
        $second = DealerShippingAddress::query()->latest('id')->firstOrFail();
        $this->assertTrue($second->is_default);
        $this->assertFalse($first->fresh()->is_default);
        $this->get(route('dealer.web.addresses.index'))->assertOk()->assertSee($second->recipient_name)
            ->assertSee(route('dealer.web.addresses.edit', $first->id));
        $this->get(route('dealer.web.addresses.edit', $first->id))->assertOk()
            ->assertSee('1 Main Street');

        $this->put(route('dealer.web.addresses.update', $first->id), [...$body,
            'recipient_name' => 'Người nhận đã sửa', 'is_default' => '1'])->assertRedirect();
        $this->assertSame('Người nhận đã sửa', $first->fresh()->recipient_name);
        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse($second->fresh()->is_default);
        $this->delete(route('dealer.web.addresses.destroy', $second->id))->assertRedirect();
        $this->assertDatabaseMissing('dealer_shipping_addresses', ['id' => $second->id]);
        $this->assertDatabaseCount('sales_orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('dealer_wallet_transactions', 0);
    }

    public function test_invalid_phone_province_ward_pair_and_missing_fields_cannot_write(): void
    {
        [$user, , $body] = $this->fixture();
        $this->actingAs($user);
        $url = route('dealer.web.addresses.store');
        $this->post($url, [...$body, 'recipient_phone' => 'letters'])->assertSessionHasErrors('recipient_phone');
        $this->post($url, [...$body, 'province_code' => 'missing'])->assertSessionHasErrors('province_code');
        $otherWard = AdministrativeWard::query()->where('province_code', '!=', '79')->firstOrFail();
        $this->post($url, [...$body, 'ward_code' => $otherWard->code])->assertSessionHasErrors('ward_code');
        $this->post($url, [...$body, 'recipient_name' => ''])->assertSessionHasErrors('recipient_name');
        $this->assertDatabaseCount('dealer_shipping_addresses', 0);
    }
}
