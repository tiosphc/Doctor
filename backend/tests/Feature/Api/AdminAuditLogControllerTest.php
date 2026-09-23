<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\BlogCategory;
use App\Models\Doctor;
use App\Models\Review;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAuditLogControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_staff_create_update_and_delete_are_audited_with_safe_diffs(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin Nguyễn']);
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/admin/staff?access_token=should-not-log&source=admin', [
            'name' => 'Mai Lễ tân',
            'email' => 'mai.audit@example.test',
            'phone' => '0900000000',
            'role' => User::ROLE_RECEPTIONIST,
            'password' => 'OriginalPassword123!',
            'password_confirmation' => 'OriginalPassword123!',
        ])->assertCreated();
        $staffId = $created->json('data.id');

        $createLog = AuditLog::query()->where('action', AuditLogger::ACTION_CREATE)->sole();
        $this->assertSame($admin->id, $createLog->actor_id);
        $this->assertSame('Admin Nguyễn', $createLog->actor_name);
        $this->assertSame('Mai Lễ tân', $createLog->target_name);
        $this->assertSame(AuditLogger::MODULE_STAFF, $createLog->module);
        $this->assertArrayNotHasKey('password', $createLog->new_values);
        $this->assertStringContainsString('source=admin', $createLog->request_url);
        $this->assertStringNotContainsString('should-not-log', $createLog->request_url);

        $this->patchJson("/api/admin/staff/{$staffId}", [
            'phone' => '0911111111',
            'password' => 'ChangedPassword123!',
            'password_confirmation' => 'ChangedPassword123!',
        ])->assertOk();

        $updateLog = AuditLog::query()->where('action', AuditLogger::ACTION_UPDATE)->sole();
        $this->assertSame(['phone' => '0900000000'], $updateLog->old_values);
        $this->assertSame(['phone' => '0911111111'], $updateLog->new_values);
        $this->assertNull($updateLog->metadata);
        $this->assertStringContainsString('thay đổi mật khẩu nhân viên', $updateLog->description);
        $this->assertStringNotContainsString('password', json_encode($updateLog->old_values));
        $this->assertStringNotContainsString('ChangedPassword123!', json_encode($updateLog->toArray()));

        $this->deleteJson("/api/admin/staff/{$staffId}")->assertOk();

        $deleteLog = AuditLog::query()->where('action', AuditLogger::ACTION_DELETE)->sole();
        $this->assertSame($staffId, $deleteLog->target_id);
        $this->assertSame('Mai Lễ tân', $deleteLog->target_name);
        $this->assertDatabaseMissing('users', ['id' => $staffId]);
        $this->assertModelExists($deleteLog->fresh());
    }

    public function test_receptionist_and_doctor_appointment_transitions_record_real_actors(): void
    {
        $receptionist = User::factory()->receptionist()->create(['name' => 'Mai']);
        $doctorUser = User::factory()->doctor()->create(['name' => 'BS. Minh']);
        $doctor = Doctor::factory()->create(['user_id' => $doctorUser->id]);
        $appointment = Appointment::factory()->guest()->for($doctor)->create([
            'booking_code' => 'BK-1025',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        Sanctum::actingAs($receptionist);
        $this->patchJson("/api/receptionist/appointments/{$appointment->id}/check-in")->assertOk();

        Sanctum::actingAs($doctorUser);
        $this->patchJson("/api/doctor/appointments/{$appointment->id}/start")->assertOk();
        $this->patchJson("/api/doctor/appointments/{$appointment->id}/complete")->assertOk();

        $logs = AuditLog::query()->where('target_id', $appointment->id)->orderBy('id')->get();
        $this->assertSame([
            AuditLogger::ACTION_CHECK_IN,
            AuditLogger::ACTION_START_EXAMINATION,
            AuditLogger::ACTION_FINISH_EXAMINATION,
        ], $logs->pluck('action')->all());
        $this->assertSame(['Mai', 'BS. Minh', 'BS. Minh'], $logs->pluck('actor_name')->all());
        $this->assertSame('confirmed', $logs[0]->old_values['status']);
        $this->assertSame('checked_in', $logs[0]->new_values['status']);
    }

    public function test_final_completion_is_audited_and_failed_transition_creates_no_success_log(): void
    {
        $staff = User::factory()->receptionist()->create();
        $appointment = Appointment::factory()->guest()->create([
            'status' => Appointment::STATUS_TREATMENT_DONE,
            'booking_code' => 'BK-COMPLETE',
        ]);
        Sanctum::actingAs($staff);

        $this->patchJson("/api/receptionist/appointments/{$appointment->id}/complete")->assertOk();
        $this->patchJson("/api/receptionist/appointments/{$appointment->id}/complete")->assertConflict();

        $this->assertSame(1, AuditLog::query()
            ->where('target_id', $appointment->id)
            ->where('action', AuditLogger::ACTION_COMPLETE)
            ->count());
    }

    public function test_admin_mutations_across_existing_modules_are_audited(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(['name' => 'Admin hệ thống']));

        $doctorId = $this->postJson('/api/admin/doctors', [
            'name' => 'BS. Audit',
            'specialty' => 'Da liễu',
            'email' => 'doctor.audit@example.test',
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/admin/doctors/{$doctorId}", [
            'status' => Doctor::STATUS_INACTIVE,
        ])->assertOk();

        $category = ServiceCategory::factory()->create();
        $serviceId = $this->postJson('/api/admin/services', [
            'category_id' => $category->id,
            'name' => 'Dịch vụ Audit',
            'slug' => 'dich-vu-audit',
            'duration' => 60,
            'price' => '3000000',
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/admin/services/{$serviceId}", ['price' => '3500000'])
            ->assertOk();

        $review = Review::factory()->create(['status' => Review::STATUS_PUBLISHED]);
        $this->patchJson("/api/admin/reviews/{$review->id}", ['status' => Review::STATUS_HIDDEN])
            ->assertOk();

        $voucher = Voucher::factory()->create();
        $this->patchJson("/api/admin/vouchers/{$voucher->id}/revoke")->assertOk();

        $this->postJson('/api/admin/blogs', [
            'title' => 'Bài viết Audit',
            'category_id' => BlogCategory::factory()->create()->id,
            'excerpt' => 'Mô tả ngắn cho bài viết.',
            'content' => 'Nội dung bài viết phục vụ kiểm thử Audit Log.',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['module' => AuditLogger::MODULE_DOCTOR, 'action' => AuditLogger::ACTION_CREATE]);
        $this->assertDatabaseHas('audit_logs', ['module' => AuditLogger::MODULE_DOCTOR, 'action' => AuditLogger::ACTION_DEACTIVATE]);
        $this->assertDatabaseHas('audit_logs', ['module' => AuditLogger::MODULE_SERVICE, 'action' => AuditLogger::ACTION_CREATE]);
        $this->assertDatabaseHas('audit_logs', ['module' => AuditLogger::MODULE_SERVICE, 'action' => AuditLogger::ACTION_UPDATE]);
        $this->assertDatabaseHas('audit_logs', ['module' => AuditLogger::MODULE_REVIEW, 'action' => AuditLogger::ACTION_DEACTIVATE]);
        $this->assertDatabaseHas('audit_logs', ['module' => AuditLogger::MODULE_VOUCHER, 'action' => AuditLogger::ACTION_DEACTIVATE]);
        $this->assertDatabaseHas('audit_logs', ['module' => AuditLogger::MODULE_BLOG, 'action' => AuditLogger::ACTION_CREATE]);
    }

    public function test_admin_can_list_show_search_filter_and_paginate_audit_logs(): void
    {
        $admin = User::factory()->admin()->create();
        AuditLog::factory()->count(26)->for($admin, 'actor')->create([
            'actor_name' => 'Admin Bộ lọc',
            'actor_role' => User::ROLE_ADMIN,
            'module' => AuditLogger::MODULE_STAFF,
            'action' => AuditLogger::ACTION_UPDATE,
            'target_name' => 'BK-1025',
        ]);
        AuditLog::factory()->create([
            'module' => AuditLogger::MODULE_SERVICE,
            'action' => AuditLogger::ACTION_CREATE,
            'target_name' => 'Dịch vụ khác',
        ]);
        Sanctum::actingAs($admin);

        $firstPage = $this->getJson('/api/admin/audit-logs?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 27)
            ->assertJsonPath('meta.last_page', 3);
        $id = $firstPage->json('data.0.id');

        $this->getJson('/api/admin/audit-logs?search=BK-1025&role=admin&module=STAFF&action=UPDATE')
            ->assertOk()
            ->assertJsonPath('meta.total', 26);
        $this->getJson("/api/admin/audit-logs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id);
    }

    #[DataProvider('forbiddenRoles')]
    public function test_non_admin_roles_cannot_access_audit_log_api(string $role): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->getJson('/api/admin/audit-logs')->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function forbiddenRoles(): array
    {
        return [
            'doctor' => [User::ROLE_DOCTOR],
            'receptionist' => [User::ROLE_RECEPTIONIST],
            'customer' => [User::ROLE_CUSTOMER],
        ];
    }

    public function test_unauthenticated_user_cannot_access_audit_log_api(): void
    {
        $this->getJson('/api/admin/audit-logs')->assertUnauthorized();
    }
}
