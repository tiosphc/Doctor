<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_access_admin_api(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/doctors')->assertOk();
    }

    public function test_customer_receives_403_from_admin_api(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->getJson('/api/admin/doctors')
            ->assertForbidden()
            ->assertJsonPath('message', 'Administrator access is required.');
    }

    public function test_guest_receives_401_from_admin_api(): void
    {
        $this->getJson('/api/admin/doctors')->assertUnauthorized();
    }
}
