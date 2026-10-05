<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthLoginRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_valid_credentials_return_200_and_regenerate_the_session(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'login@example.test',
            'password' => 'password123',
        ]);
        $session = app('session')->driver();
        $session->start();
        $sessionIdBeforeLogin = $session->getId();

        $this->withHeaders($this->spaHeaders())->postJson('/api/login', [
            'email' => 'login@example.test',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', User::ROLE_ADMIN)
            ->assertJsonMissingPaths(['data.password', 'data.remember_token']);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($sessionIdBeforeLogin, $session->getId());
    }

    public function test_wrong_password_returns_401(): void
    {
        User::factory()->create([
            'email' => 'login@example.test',
            'password' => 'password123',
        ]);

        $this->withHeaders($this->spaHeaders())->postJson('/api/login', [
            'email' => 'login@example.test',
            'password' => 'wrong-password',
        ])->assertUnauthorized()
            ->assertExactJson(['message' => 'The provided credentials are incorrect.']);

        $this->assertGuest('web');
    }

    public function test_unknown_user_returns_401(): void
    {
        $this->withHeaders($this->spaHeaders())->postJson('/api/login', [
            'email' => 'missing@example.test',
            'password' => 'password123',
        ])->assertUnauthorized()
            ->assertExactJson(['message' => 'The provided credentials are incorrect.']);

        $this->assertGuest('web');
    }

    public function test_valid_login_persists_a_regenerated_database_session(): void
    {
        config()->set('session.driver', 'database');
        $user = User::factory()->admin()->create([
            'email' => 'database.session@example.test',
            'password' => 'password123',
        ]);
        $session = app('session')->driver();
        $session->start();
        $sessionIdBeforeLogin = $session->getId();

        $this->withHeaders($this->spaHeaders())->postJson('/api/login', [
            'email' => 'database.session@example.test',
            'password' => 'password123',
        ])->assertOk();

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($sessionIdBeforeLogin, $session->getId());
        $this->assertDatabaseHas('sessions', ['id' => $session->getId(), 'user_id' => $user->id]);
    }

    public function test_production_spa_origin_can_login_when_configured_as_stateful(): void
    {
        config()->set('sanctum.stateful', [...config('sanctum.stateful'), 'drjunie.online']);
        $user = User::factory()->admin()->create([
            'email' => 'production.origin@example.test',
            'password' => 'password123',
        ]);

        $this->withHeaders([
            'Origin' => 'https://drjunie.online',
            'Referer' => 'https://drjunie.online/',
        ])->postJson('/api/login', [
            'email' => 'production.origin@example.test',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user, 'web');
    }

    /** @return array<string, string> */
    private function spaHeaders(): array
    {
        return [
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ];
    }
}
