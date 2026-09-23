<?php

namespace Tests\Feature\Api;

use App\Models\Doctor;
use App\Models\Review;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReviewControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_filters_and_moderates_without_rewriting_customer_content(): void
    {
        $review = Review::factory()->create(['rating' => 2, 'comment' => 'Nhận xét nguyên bản.']);
        Review::factory()->create(['rating' => 5]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/reviews?rating=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $review->id);
        $this->patchJson("/api/admin/reviews/{$review->id}", [
            'status' => Review::STATUS_HIDDEN,
            'rating' => 5,
            'comment' => 'Bị sửa',
        ])->assertOk()->assertJsonPath('data.status', Review::STATUS_HIDDEN);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id, 'rating' => 2, 'comment' => 'Nhận xét nguyên bản.',
            'status' => Review::STATUS_HIDDEN,
        ]);
    }

    public function test_doctor_reads_only_own_published_reviews_and_cannot_moderate(): void
    {
        $doctor = Doctor::factory()->create();
        $doctorUser = User::factory()->doctor()->create();
        $doctor->update(['user_id' => $doctorUser->id]);
        $visible = Review::factory()->create(['doctor_id' => $doctor->id]);
        Review::factory()->create(['doctor_id' => $doctor->id, 'status' => Review::STATUS_HIDDEN]);
        Review::factory()->create();
        Sanctum::actingAs($doctorUser);

        $this->getJson('/api/doctor/reviews')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
        $this->patchJson("/api/admin/reviews/{$visible->id}", ['status' => Review::STATUS_HIDDEN])
            ->assertForbidden();
    }

    public function test_admin_views_and_revokes_active_reward_but_cannot_revoke_used_voucher(): void
    {
        $active = Voucher::factory()->create();
        $used = Voucher::factory()->create([
            'source' => Voucher::SOURCE_LOYALTY_MILESTONE,
            'status' => Voucher::STATUS_USED,
            'used_at' => now(),
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/vouchers')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/vouchers?source='.Voucher::SOURCE_REVIEW_REWARD)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
        $this->getJson('/api/admin/vouchers?source=invalid')->assertUnprocessable();
        $this->patchJson("/api/admin/vouchers/{$active->id}/revoke")
            ->assertOk()->assertJsonPath('data.status', Voucher::STATUS_REVOKED);
        $this->patchJson("/api/admin/vouchers/{$used->id}/revoke")->assertConflict();
    }

    public function test_admin_voucher_status_filter_uses_effective_expiry(): void
    {
        $active = Voucher::factory()->create(['expires_at' => now()->addDay()]);
        $effectivelyExpired = Voucher::factory()->create([
            'status' => Voucher::STATUS_ACTIVE,
            'expires_at' => now()->subMinute(),
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/vouchers?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->getJson('/api/admin/vouchers?status=expired')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $effectivelyExpired->id)
            ->assertJsonPath('data.0.status', Voucher::STATUS_EXPIRED);
    }
}
