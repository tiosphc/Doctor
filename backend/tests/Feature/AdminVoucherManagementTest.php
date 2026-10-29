<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminVoucherManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_creates_normalized_general_voucher_and_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/vouchers', [
            'code' => '  junie10  ',
            'value' => 10,
            'expires_at' => now()->addDays(10)->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.code', 'JUNIE10')
            ->assertJsonPath('data.source', Voucher::SOURCE_ADMIN)
            ->assertJsonPath('data.customer', null);

        $voucherId = $response->json('data.id');

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucherId,
            'code' => 'JUNIE10',
            'user_id' => null,
            'source' => Voucher::SOURCE_ADMIN,
            'source_id' => null,
            'status' => Voucher::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => AuditLogger::ACTION_CREATE,
            'module' => AuditLogger::MODULE_VOUCHER,
            'target_id' => $voucherId,
            'target_name' => 'JUNIE10',
        ]);
    }

    public function test_admin_lists_only_requested_source_and_searches_by_code(): void
    {
        $adminVoucher = Voucher::factory()->admin()->create(['code' => 'JUN-WELCOME']);
        Voucher::factory()->create(['code' => 'RVW-REWARD-01']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/vouchers?source=admin&search=welcome')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $adminVoucher->id)
            ->assertJsonPath('data.0.customer', null);

        $this->getJson('/api/admin/vouchers?source=review_reward')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'RVW-REWARD-01');
    }

    public function test_admin_generates_short_unique_code(): void
    {
        Voucher::factory()->admin()->create(['code' => 'JUN-ABC123']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $code = $this->getJson('/api/admin/vouchers/generate-code')
            ->assertOk()
            ->json('code');

        $this->assertIsString($code);
        $this->assertMatchesRegularExpression('/^JUN-[A-Z0-9]{6}$/', $code);
        $this->assertNotSame('JUN-ABC123', $code);
        $this->assertDatabaseMissing('vouchers', ['code' => $code]);
    }

    public function test_admin_voucher_validation_rejects_duplicate_invalid_value_and_past_expiry(): void
    {
        Voucher::factory()->admin()->create(['code' => 'JUNIE10']);
        Sanctum::actingAs(User::factory()->admin()->create());
        $future = now()->addDay()->toDateString();

        $this->postJson('/api/admin/vouchers', [
            'code' => ' junie10 ', 'value' => 10, 'expires_at' => $future,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code'])
            ->assertJsonPath('errors.code.0', 'Mã voucher đã tồn tại.');

        foreach ([0, -5, 7, 101] as $index => $value) {
            $this->postJson('/api/admin/vouchers', [
                'code' => 'VALID'.($index + 1),
                'value' => $value,
                'expires_at' => $future,
            ])->assertUnprocessable()->assertJsonValidationErrors(['value']);
        }

        $this->postJson('/api/admin/vouchers', [
            'code' => 'VALID10',
            'value' => 10,
            'expires_at' => now()->subDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['expires_at']);
    }

    public function test_admin_revokes_general_voucher_and_writes_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $voucher = Voucher::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/vouchers/{$voucher->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', Voucher::STATUS_REVOKED);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => AuditLogger::ACTION_DEACTIVATE,
            'module' => AuditLogger::MODULE_VOUCHER,
            'target_id' => $voucher->id,
            'target_name' => $voucher->code,
        ]);
    }

    public function test_admin_deletes_only_unused_general_voucher_and_keeps_audit_snapshot(): void
    {
        $admin = User::factory()->admin()->create();
        $unused = Voucher::factory()->admin()->create(['code' => 'JUN-DELETE']);
        $used = Voucher::factory()->admin()->create([
            'status' => Voucher::STATUS_USED,
            'used_at' => now(),
        ]);
        $referenced = Voucher::factory()->admin()->create();
        Appointment::factory()->create(['voucher_id' => $referenced->id]);
        $cancelledReference = Voucher::factory()->admin()->create();
        Appointment::factory()->create([
            'voucher_id' => $cancelledReference->id,
            'status' => Appointment::STATUS_CANCELLED,
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/vouchers/{$unused->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Xóa voucher thành công.');

        $this->assertDatabaseMissing('vouchers', ['id' => $unused->id]);
        $deleteLog = AuditLog::query()
            ->where('action', AuditLogger::ACTION_DELETE)
            ->where('module', AuditLogger::MODULE_VOUCHER)
            ->where('target_id', $unused->id)
            ->firstOrFail();
        $this->assertSame('JUN-DELETE', $deleteLog->target_name);
        $this->assertSame('JUN-DELETE', $deleteLog->old_values['code']);

        $this->deleteJson("/api/admin/vouchers/{$used->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'Voucher đã được sử dụng và không thể xóa.');
        $this->deleteJson("/api/admin/vouchers/{$referenced->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'Voucher đã được sử dụng và không thể xóa.');
        $this->deleteJson("/api/admin/vouchers/{$cancelledReference->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'Voucher đã được sử dụng và không thể xóa.');
        $this->assertDatabaseHas('vouchers', ['id' => $used->id]);
        $this->assertDatabaseHas('vouchers', ['id' => $referenced->id]);
        $this->assertDatabaseHas('vouchers', ['id' => $cancelledReference->id]);
    }

    public function test_non_admins_cannot_manage_vouchers(): void
    {
        $payload = [
            'code' => 'JUN-NOAUTH',
            'value' => 10,
            'expires_at' => now()->addDay()->toDateString(),
        ];

        $this->postJson('/api/admin/vouchers', $payload)->assertUnauthorized();

        foreach ([User::factory()->customer()->create(), User::factory()->doctor()->create()] as $user) {
            Sanctum::actingAs($user);
            $this->postJson('/api/admin/vouchers', $payload)->assertForbidden();
        }

        $this->assertDatabaseMissing('vouchers', ['code' => 'JUN-NOAUTH']);
    }
}
