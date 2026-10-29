<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthDiagnosticControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_diagnostic_returns_404_when_the_secret_is_not_configured(): void
    {
        config()->set('storage_diagnostics.key', null);

        $this->getJson('/api/internal/diagnostics/auth')->assertNotFound();
        $this->postJson('/api/internal/diagnostics/auth/login')->assertNotFound();
    }

    public function test_diagnostic_returns_403_for_an_incorrect_secret(): void
    {
        config()->set('storage_diagnostics.key', 'correct-key');

        $this->withHeader('X-Diagnostic-Key', 'incorrect-key')
            ->getJson('/api/internal/diagnostics/auth')->assertForbidden();
        $this->withHeader('X-Diagnostic-Key', 'incorrect-key')
            ->postJson('/api/internal/diagnostics/auth/login')->assertForbidden();
    }

    public function test_authorized_status_reports_safe_auth_facts(): void
    {
        config()->set('storage_diagnostics.key', 'correct-key');

        $response = $this->withHeader('X-Diagnostic-Key', 'correct-key')
            ->getJson('/api/internal/diagnostics/auth')
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('user_model_loadable', true)
            ->assertJsonPath('users_table_exists', true)
            ->assertJsonPath('auth_provider_model', User::class)
            ->assertJsonPath('session_driver', 'array')
            ->assertJsonPath('sessions_table_exists', true)
            ->assertJsonPath('session_table_columns_valid', true)
            ->assertJsonPath('app_key_valid', true)
            ->assertJsonMissing(['correct-key']);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_diagnostic_login_reports_unknown_user_without_an_exception(): void
    {
        config()->set('storage_diagnostics.key', 'correct-key');

        $this->withHeaders($this->authorizedSpaHeaders())
            ->postJson('/api/internal/diagnostics/auth/login', [
                'email' => 'missing@example.test',
                'password' => 'password123',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('stage', 'user_lookup')
            ->assertJsonMissingPaths(['exception_class', 'file', 'line']);
    }

    public function test_diagnostic_login_reports_wrong_password_without_an_exception(): void
    {
        config()->set('storage_diagnostics.key', 'correct-key');
        User::factory()->admin()->create([
            'email' => 'diagnostic@example.test',
            'password' => 'password123',
        ]);

        $this->withHeaders($this->authorizedSpaHeaders())
            ->postJson('/api/internal/diagnostics/auth/login', [
                'email' => 'diagnostic@example.test',
                'password' => 'wrong-password',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('stage', 'password_check')
            ->assertJsonMissingPaths(['exception_class', 'file', 'line']);
    }

    public function test_diagnostic_login_reports_a_user_model_failure_without_secrets(): void
    {
        config()->set('storage_diagnostics.key', 'correct-key');
        config()->set('auth.providers.users.model', 'App\\Models\\MissingUser');

        $response = $this->withHeaders($this->authorizedSpaHeaders())
            ->postJson('/api/internal/diagnostics/auth/login', [
                'email' => 'missing@example.test',
                'password' => 'private-test-password',
            ])
            ->assertInternalServerError()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('stage', 'user_lookup')
            ->assertJsonPath('exception_class', \Error::class)
            ->assertJsonPath('file', 'EloquentUserProvider.php');

        $this->assertIsInt($response->json('line'));
        $this->assertStringNotContainsString('private-test-password', $response->getContent());
        $this->assertStringNotContainsString('correct-key', $response->getContent());
    }

    public function test_diagnostic_login_completes_and_regenerates_the_session(): void
    {
        config()->set('storage_diagnostics.key', 'correct-key');
        $user = User::factory()->admin()->create([
            'email' => 'diagnostic@example.test',
            'password' => 'password123',
        ]);
        $session = app('session')->driver();
        $session->start();
        $sessionIdBeforeLogin = $session->getId();

        $this->withHeaders($this->authorizedSpaHeaders())
            ->postJson('/api/internal/diagnostics/auth/login', [
                'email' => 'diagnostic@example.test',
                'password' => 'password123',
            ])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'stage' => 'completed']);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($sessionIdBeforeLogin, $session->getId());
    }

    public function test_diagnostic_login_identifies_a_missing_session_at_regeneration(): void
    {
        config()->set('storage_diagnostics.key', 'correct-key');
        User::factory()->admin()->create([
            'email' => 'diagnostic@example.test',
            'password' => 'password123',
        ]);

        $this->withHeader('X-Diagnostic-Key', 'correct-key')
            ->postJson('/api/internal/diagnostics/auth/login', [
                'email' => 'diagnostic@example.test',
                'password' => 'password123',
            ])
            ->assertInternalServerError()
            ->assertJsonPath('stage', 'session_regenerate')
            ->assertJsonPath('exception_class', \RuntimeException::class)
            ->assertJsonPath('file', 'Request.php');
    }

    /** @return array<string, string> */
    private function authorizedSpaHeaders(): array
    {
        return [
            'X-Diagnostic-Key' => 'correct-key',
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ];
    }
}
