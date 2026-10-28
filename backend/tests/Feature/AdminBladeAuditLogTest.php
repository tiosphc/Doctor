<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminBladeAuditLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_audit_screens_require_admin_and_have_no_write_route(): void
    {
        $this->get(route('admin.web.audit-logs.index'))->assertRedirect(route('login'));
        $this->get(route('admin.web.audit-logs.show', 999999))->assertRedirect(route('login'));

        $log = AuditLog::factory()->create();
        $this->actingAs(User::factory()->customer()->create());
        $this->get(route('admin.web.audit-logs.index'))->assertForbidden();
        $this->get(route('admin.web.audit-logs.show', $log))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.web.audit-logs.show', 999999))->assertNotFound();
        $this->post(route('admin.web.audit-logs.index'))->assertMethodNotAllowed();
    }

    public function test_audit_list_filters_dates_module_action_actor_and_paginates(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin Bộ lọc']);
        $this->actingAs($admin);
        AuditLog::factory()->count(26)->for($admin, 'actor')->create([
            'actor_name' => $admin->name,
            'actor_role' => User::ROLE_ADMIN,
            'module' => AuditLogger::MODULE_STAFF,
            'action' => AuditLogger::ACTION_UPDATE,
            'target_name' => 'BK-1025',
            'created_at' => '2026-09-20 10:00:00',
        ]);
        AuditLog::factory()->for($admin, 'actor')->create([
            'module' => AuditLogger::MODULE_SERVICE,
            'action' => AuditLogger::ACTION_CREATE,
            'target_name' => 'Dịch vụ khác',
            'created_at' => '2026-08-20 10:00:00',
        ]);

        $this->get(route('admin.web.audit-logs.index', ['per_page' => 10]))
            ->assertOk()->assertSee('27 bản ghi')
            ->assertViewHas('logs', fn ($logs): bool => $logs->count() === 10);
        $this->get(route('admin.web.audit-logs.index', ['per_page' => 10, 'page' => 3]))
            ->assertOk()->assertViewHas('logs', fn ($logs): bool => $logs->count() === 7);
        $this->get(route('admin.web.audit-logs.index', [
            'search' => 'BK-1025', 'role' => 'admin',
            'module' => 'STAFF', 'action' => 'UPDATE',
            'actor_id' => $admin->id, 'from' => '2026-09-01', 'to' => '2026-09-30',
        ]))->assertOk()->assertSee('26 bản ghi')->assertDontSee('Dịch vụ khác');
        $this->get(route('admin.web.audit-logs.index', ['module' => 'NOT_A_MODULE']))
            ->assertSessionHasErrors('module');
    }

    public function test_audit_detail_sanitizes_sensitive_nested_values_and_escapes_output(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $log = AuditLog::factory()->for($admin, 'actor')->create([
            'description' => '<script>alert(1)</script>',
            'old_values' => ['status' => 'safe-value', 'password' => 'old-secret-value'],
            'new_values' => ['status' => 'changed', 'nested' => ['api_token' => 'new-secret-value']],
            'metadata' => ['operation' => 'reviewed', 'secret' => 'metadata-secret-value'],
            'request_url' => 'http://localhost/?access_token=sensitive-url-value',
        ]);

        $this->get(route('admin.web.audit-logs.show', $log))
            ->assertOk()->assertSee('safe-value')->assertSee('reviewed')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('old-secret-value')->assertDontSee('new-secret-value')
            ->assertDontSee('metadata-secret-value')->assertDontSee('sensitive-url-value');
    }
}
