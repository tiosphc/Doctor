<?php

namespace Tests\Feature\Api;

use App\Models\Doctor;
use App\Models\User;
use App\Notifications\DoctorAccountInvitation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class DoctorAccountInvitationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_creation_atomically_creates_linked_pending_doctor_account_and_invitation(): void
    {
        Notification::fake();
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->postJson('/api/admin/doctors', [
            'name' => 'Dr. Account',
            'specialty' => 'Dermatology',
            'email' => 'DOCTOR.ACCOUNT@example.test',
            'phone' => '0901234567',
        ])->assertCreated()
            ->assertJsonPath('invitation_queued', true)
            ->assertJsonPath('data.account_status', 'pending_setup')
            ->assertJsonMissingPaths(['data.password', 'data.remember_token']);

        $user = User::query()->where('email', 'doctor.account@example.test')->firstOrFail();
        $doctor = Doctor::query()->findOrFail($response->json('data.id'));

        $this->assertSame(User::ROLE_DOCTOR, $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->email_verified_at);
        $this->assertSame($user->id, $doctor->user_id);
        $this->assertSame($user->name, $doctor->name);
        $this->assertSame($user->phone, $doctor->phone);
        $this->assertFalse(Hash::needsRehash($user->password));
        Notification::assertSentTo($user, DoctorAccountInvitation::class);
    }

    public function test_duplicate_account_email_is_rejected_without_creating_doctor(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'used@example.test']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/doctors', [
            'name' => 'Dr. Duplicate',
            'specialty' => 'Dermatology',
            'email' => 'USED@example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->assertDatabaseMissing('doctors', ['name' => 'Dr. Duplicate']);
        Notification::assertNothingSent();
    }

    public function test_non_admin_cannot_create_or_resend_doctor_invitation(): void
    {
        $customer = User::factory()->customer()->create();
        $doctor = Doctor::factory()->create();
        Sanctum::actingAs($customer);

        $this->postJson('/api/admin/doctors', [
            'name' => 'Dr. Forbidden',
            'specialty' => 'Dermatology',
            'email' => 'forbidden@example.test',
        ])->assertForbidden();
        $this->postJson("/api/admin/doctors/{$doctor->id}/resend-invitation")->assertForbidden();
    }

    public function test_valid_setup_token_sets_password_once_and_activates_account(): void
    {
        $user = User::factory()->doctor()->pendingPasswordSetup()->create();
        Doctor::factory()->for($user)->create();
        $token = Password::broker()->createToken($user);
        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ];

        $this->postJson('/api/auth/setup-password', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Mật khẩu đã được thiết lập thành công.');

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('NewSecurePassword123!', $user->password));
        $this->assertFalse(Password::broker()->tokenExists($user, $token));
        $this->postJson('/api/auth/setup-password', $payload)->assertUnprocessable();
    }

    public function test_invalid_and_expired_setup_tokens_are_rejected_without_changing_account(): void
    {
        $user = User::factory()->doctor()->pendingPasswordSetup()->create();
        Doctor::factory()->for($user)->create();
        $originalPassword = $user->password;

        $this->postJson('/api/auth/setup-password', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['token']);

        $token = Password::broker()->createToken($user);
        $this->travel(61)->minutes();
        $this->postJson('/api/auth/setup-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['token']);

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertSame($originalPassword, $user->password);
    }

    public function test_admin_can_resend_pending_invitation_and_old_token_is_revoked(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->doctor()->pendingPasswordSetup()->create();
        $doctor = Doctor::factory()->for($user)->create();
        $oldToken = Password::broker()->createToken($user);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/doctors/{$doctor->id}/resend-invitation")->assertOk();

        $this->assertFalse(Password::broker()->tokenExists($user, $oldToken));
        Notification::assertSentTo($user, DoctorAccountInvitation::class);

        $user->update(['must_change_password' => false]);
        $this->postJson("/api/admin/doctors/{$doctor->id}/resend-invitation")->assertConflict();
    }

    public function test_doctor_identity_update_stays_synchronized_and_suspension_blocks_login(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $doctorUser = User::factory()->doctor()->create([
            'email' => 'old.doctor@example.test',
            'password' => 'password123',
        ]);
        $doctor = Doctor::factory()->for($doctorUser)->create([
            'name' => $doctorUser->name,
            'email' => $doctorUser->email,
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/doctors/{$doctor->id}", [
            'name' => 'Dr. Synchronized',
            'email' => 'synchronized@example.test',
            'phone' => '0912345678',
            'status' => Doctor::STATUS_INACTIVE,
        ])->assertOk()->assertJsonPath('data.account_status', 'suspended');

        $this->assertDatabaseHas('users', [
            'id' => $doctorUser->id,
            'name' => 'Dr. Synchronized',
            'email' => 'synchronized@example.test',
            'phone' => '0912345678',
        ]);
        $this->assertDatabaseHas('doctors', [
            'id' => $doctor->id,
            'name' => 'Dr. Synchronized',
            'email' => 'synchronized@example.test',
            'phone' => '0912345678',
            'status' => Doctor::STATUS_INACTIVE,
        ]);

        auth('web')->forgetUser();
        $this->withHeaders($this->spaHeaders())->postJson('/api/login', [
            'email' => 'synchronized@example.test',
            'password' => 'password123',
        ])->assertForbidden();
    }

    public function test_invitation_dispatch_failure_does_not_roll_back_created_records(): void
    {
        Password::shouldReceive('broker')->once()->andThrow(new RuntimeException('Queue unavailable'));
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/doctors', [
            'name' => 'Dr. Durable',
            'specialty' => 'Dermatology',
            'email' => 'durable@example.test',
        ])->assertCreated()->assertJsonPath('invitation_queued', false);

        $user = User::query()->where('email', 'durable@example.test')->firstOrFail();
        $this->assertDatabaseHas('doctors', ['user_id' => $user->id, 'name' => 'Dr. Durable']);
    }

    public function test_legacy_unlinked_doctor_remains_available_through_public_api(): void
    {
        $doctor = Doctor::factory()->create(['user_id' => null]);

        $this->getJson("/api/doctors/{$doctor->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $doctor->id)
            ->assertJsonPath('data.name', $doctor->name);
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
