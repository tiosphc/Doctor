<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\User;
use App\Services\StaffAccountService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStaffControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_creates_one_receptionist_without_dispatching_a_welcome_job(): void
    {
        Queue::fake();
        Notification::fake();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/staff', $this->staffPayload())
            ->assertCreated()
            ->assertJsonPath('message', 'Tạo nhân viên thành công.')
            ->assertJsonPath('data.email', 'receptionist@example.test')
            ->assertJsonPath('data.role', User::ROLE_RECEPTIONIST);

        $staff = User::query()->where('email', 'receptionist@example.test')->sole();
        $this->assertSame(User::ROLE_RECEPTIONIST, $staff->role);
        $this->assertTrue(Hash::check('ReceptionistPassword123!', $staff->password));
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
    }

    public function test_duplicate_create_returns_vietnamese_email_error_and_keeps_one_account(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $payload = $this->staffPayload();

        $this->postJson('/api/admin/staff', $payload)->assertCreated();
        $this->postJson('/api/admin/staff', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email này đã được sử dụng.');

        $this->assertSame(
            1,
            User::query()->where('email', 'receptionist@example.test')->count(),
        );
    }

    public function test_database_unique_constraint_translates_a_create_race_to_email_validation_error(): void
    {
        User::factory()->receptionist()->create(['email' => 'race.staff@example.test']);

        try {
            app(StaffAccountService::class)->create([
                'name' => 'Concurrent Staff',
                'email' => 'race.staff@example.test',
                'phone' => null,
                'password' => 'ReceptionistPassword123!',
            ]);
            $this->fail('Expected duplicate staff email validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Email này đã được sử dụng.'],
                $exception->errors()['email'],
            );
        }

        $this->assertSame(
            1,
            User::query()->where('email', 'race.staff@example.test')->count(),
        );
    }

    public function test_update_accepts_the_current_email_and_keeps_the_existing_password_when_blank(): void
    {
        $staff = User::factory()->receptionist()->create([
            'email' => 'current.staff@example.test',
            'password' => 'OriginalPassword123!',
        ]);
        $originalPasswordHash = $staff->password;
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/admin/staff/{$staff->id}", [
            'name' => 'Updated Receptionist',
            'email' => ' CURRENT.STAFF@example.test ',
            'phone' => '0901234567',
            'password' => '',
            'password_confirmation' => '',
        ])->assertOk()
            ->assertJsonPath('message', 'Cập nhật nhân viên thành công.')
            ->assertJsonPath('data.email', 'current.staff@example.test');

        $staff->refresh();
        $this->assertSame('Updated Receptionist', $staff->name);
        $this->assertSame($originalPasswordHash, $staff->password);
    }

    public function test_update_rejects_another_users_email_in_vietnamese(): void
    {
        $staff = User::factory()->receptionist()->create();
        $otherStaff = User::factory()->receptionist()->create(['email' => 'used.staff@example.test']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/admin/staff/{$staff->id}", [
            'email' => $otherStaff->email,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email này đã được sử dụng.');

        $this->assertNotSame($otherStaff->email, $staff->fresh()->email);
    }

    public function test_update_hashes_a_new_password_and_allows_login_with_it(): void
    {
        $staff = User::factory()->receptionist()->create([
            'email' => 'password.staff@example.test',
            'password' => 'OriginalPassword123!',
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/admin/staff/{$staff->id}", [
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword123!', $staff->fresh()->password));
        auth('web')->forgetUser();
        $this->withHeaders($this->spaHeaders())->postJson('/api/login', [
            'email' => $staff->email,
            'password' => 'NewPassword123!',
        ])->assertOk()->assertJsonPath('data.role', User::ROLE_RECEPTIONIST);
    }

    public function test_admin_hard_deletes_an_unused_receptionist_and_login_stops_working(): void
    {
        $staff = User::factory()->receptionist()->create([
            'email' => 'delete.staff@example.test',
            'password' => 'ReceptionistPassword123!',
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/admin/staff/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Xóa nhân viên thành công.');

        $this->assertModelMissing($staff);
        auth('web')->forgetUser();
        $this->withHeaders($this->spaHeaders())->postJson('/api/login', [
            'email' => 'delete.staff@example.test',
            'password' => 'ReceptionistPassword123!',
        ])->assertUnauthorized();
    }

    public function test_staff_endpoint_does_not_delete_a_doctor_account(): void
    {
        $doctor = User::factory()->doctor()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/admin/staff/{$doctor->id}")->assertNotFound();

        $this->assertModelExists($doctor);
    }

    public function test_delete_refuses_a_receptionist_with_domain_history(): void
    {
        $staff = User::factory()->receptionist()->create();
        Appointment::factory()->for($staff)->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/admin/staff/{$staff->id}")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Không thể xóa nhân viên vì tài khoản đã có dữ liệu nghiệp vụ liên quan.',
            );

        $this->assertModelExists($staff);
    }

    public function test_non_admin_cannot_delete_a_receptionist(): void
    {
        $staff = User::factory()->receptionist()->create();
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->deleteJson("/api/admin/staff/{$staff->id}")->assertForbidden();

        $this->assertModelExists($staff);
    }

    public function test_database_unavailable_returns_server_error_without_creating_staff(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $originalPort = Config::get('database.connections.mysql.port');
        Config::set('database.connections.mysql.port', 1);
        DB::purge('mysql');

        try {
            $this->postJson('/api/admin/staff', $this->staffPayload())->assertServerError();
        } finally {
            Config::set('database.connections.mysql.port', $originalPort);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        $this->assertDatabaseMissing('users', ['email' => 'receptionist@example.test']);
    }

    /** @return array<string, string> */
    private function staffPayload(): array
    {
        return [
            'name' => 'Front Desk Receptionist',
            'email' => ' RECEPTIONIST@example.test ',
            'phone' => '0901234567',
            'role' => User::ROLE_RECEPTIONIST,
            'password' => 'ReceptionistPassword123!',
            'password_confirmation' => 'ReceptionistPassword123!',
        ];
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
