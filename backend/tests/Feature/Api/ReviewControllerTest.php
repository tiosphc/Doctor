<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Review;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\ReviewRewardNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReviewControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_customer_reviews_own_completed_appointment_and_receives_one_reward(): void
    {
        Notification::fake([ReviewRewardNotification::class]);
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create(['status' => Appointment::STATUS_COMPLETED]);
        Sanctum::actingAs($customer);

        $response = $this->postJson("/api/my-appointments/{$appointment->id}/review", [
            'rating' => 1,
            'comment' => 'Tư vấn rõ ràng.',
        ])->assertCreated()->assertJsonPath('data.rating', 1)->assertJsonPath('voucher.value', '5.00');

        $this->assertDatabaseHas('reviews', [
            'id' => $response->json('data.id'), 'appointment_id' => $appointment->id,
            'user_id' => $customer->id, 'doctor_id' => $appointment->doctor_id,
            'service_id' => $appointment->service_id, 'rating' => 1,
        ]);
        $this->assertDatabaseHas('vouchers', [
            'user_id' => $customer->id, 'source' => Voucher::SOURCE_REVIEW_REWARD,
            'source_id' => $appointment->id, 'value' => 5,
        ]);
        Notification::assertSentTo($customer, ReviewRewardNotification::class);
    }

    #[DataProvider('ineligibleStatuses')]
    public function test_returns_409_when_appointment_is_not_completed(string $status): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create(['status' => $status]);
        Sanctum::actingAs($customer);

        $this->postJson("/api/my-appointments/{$appointment->id}/review", ['rating' => 4])->assertConflict();

        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public static function ineligibleStatuses(): array
    {
        return collect(Appointment::STATUSES)->reject(
            fn (string $status): bool => $status === Appointment::STATUS_COMPLETED,
        )->mapWithKeys(fn (string $status): array => [$status => [$status]])->all();
    }

    public function test_customer_cannot_review_another_customers_appointment(): void
    {
        $appointment = Appointment::factory()->create(['status' => Appointment::STATUS_COMPLETED]);
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->postJson("/api/my-appointments/{$appointment->id}/review", ['rating' => 5])->assertForbidden();
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_duplicate_review_returns_409_and_does_not_issue_second_reward(): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create(['status' => Appointment::STATUS_COMPLETED]);
        Sanctum::actingAs($customer);

        $this->postJson("/api/my-appointments/{$appointment->id}/review", ['rating' => 5])->assertCreated();
        $this->postJson("/api/my-appointments/{$appointment->id}/review", ['rating' => 2])
            ->assertConflict()->assertJsonPath('message', 'Bạn đã đánh giá lịch hẹn này.');

        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseCount('vouchers', 1);
    }

    public function test_one_star_and_five_star_reviews_receive_the_same_configured_reward_and_expiry(): void
    {
        $this->travelTo('2026-09-21 10:00:00');
        config()->set('rewards.review.percent', 7);
        config()->set('rewards.review.expiry_days', 14);
        $customer = User::factory()->customer()->create();
        $appointments = Appointment::factory()->count(2)->for($customer)->create([
            'status' => Appointment::STATUS_COMPLETED,
        ]);
        Sanctum::actingAs($customer);

        $firstVoucher = $this->postJson("/api/my-appointments/{$appointments[0]->id}/review", ['rating' => 1])
            ->assertCreated()->json('voucher');
        $secondVoucher = $this->postJson("/api/my-appointments/{$appointments[1]->id}/review", ['rating' => 5])
            ->assertCreated()->json('voucher');

        $this->assertSame('7.00', $firstVoucher['value']);
        $this->assertSame($firstVoucher['value'], $secondVoucher['value']);
        $this->assertSame('2026-10-05T03:00:00.000000Z', $firstVoucher['expires_at']);
        $this->assertNotSame($firstVoucher['code'], $secondVoucher['code']);
    }

    public function test_reward_collision_rolls_back_review_creation(): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create(['status' => Appointment::STATUS_COMPLETED]);
        Voucher::factory()->for($customer)->create(['source_id' => $appointment->id]);
        Sanctum::actingAs($customer);

        $this->postJson("/api/my-appointments/{$appointment->id}/review", ['rating' => 4])->assertConflict();

        $this->assertDatabaseMissing('reviews', ['appointment_id' => $appointment->id]);
        $this->assertSame(1, Voucher::query()->where('source_id', $appointment->id)->count());
    }

    public function test_guest_receptionist_and_doctor_cannot_create_customer_review(): void
    {
        $appointment = Appointment::factory()->create(['status' => Appointment::STATUS_COMPLETED]);

        $this->postJson("/api/my-appointments/{$appointment->id}/review", ['rating' => 4])->assertUnauthorized();
        foreach ([User::factory()->receptionist()->create(), User::factory()->doctor()->create()] as $staff) {
            Sanctum::actingAs($staff);
            $this->postJson("/api/my-appointments/{$appointment->id}/review", ['rating' => 4])->assertForbidden();
        }
        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_rating_boundaries_and_protected_fields_are_validated(): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create(['status' => Appointment::STATUS_COMPLETED]);
        Sanctum::actingAs($customer);

        foreach ([0, 6] as $rating) {
            $this->postJson("/api/my-appointments/{$appointment->id}/review", ['rating' => $rating])
                ->assertUnprocessable()->assertJsonValidationErrors(['rating']);
        }
        $this->postJson("/api/my-appointments/{$appointment->id}/review", ['rating' => 3, 'status' => 'hidden'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_customer_edits_own_hidden_review_without_creating_another_reward_or_republishing(): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create(['status' => Appointment::STATUS_COMPLETED]);
        Sanctum::actingAs($customer);
        $reviewId = $this->postJson("/api/my-appointments/{$appointment->id}/review", ['rating' => 3])->json('data.id');
        Review::query()->whereKey($reviewId)->update(['status' => Review::STATUS_HIDDEN]);

        $this->patchJson("/api/my-reviews/{$reviewId}", ['rating' => 5, 'comment' => 'Đã cập nhật.'])
            ->assertOk()->assertJsonPath('data.rating', 5)->assertJsonPath('data.status', Review::STATUS_HIDDEN);

        $this->assertDatabaseCount('vouchers', 1);
        $this->assertDatabaseHas('reviews', ['id' => $reviewId, 'status' => Review::STATUS_HIDDEN]);
    }

    public function test_customer_cannot_edit_another_customers_review(): void
    {
        $review = Review::factory()->create();
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->patchJson("/api/my-reviews/{$review->id}", ['rating' => 2])->assertForbidden();
    }

    public function test_public_reviews_and_doctor_aggregate_exclude_hidden_reviews(): void
    {
        $published = Review::factory()->create(['rating' => 5]);
        Doctor::query()->whereKey($published->doctor_id)->update(['baseline_review_count' => 123]);
        Review::factory()->create([
            'doctor_id' => $published->doctor_id, 'service_id' => $published->service_id,
            'rating' => 1, 'status' => Review::STATUS_HIDDEN,
        ]);

        $this->getJson("/api/doctors/{$published->doctor_id}/reviews")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.rating', 5);
        $this->getJson("/api/doctors/{$published->doctor_id}")
            ->assertOk()->assertJsonPath('data.average_rating', 5)
            ->assertJsonPath('data.review_count', 124)
            ->assertJsonPath('data.rating_distribution.5', 124)
            ->assertJsonPath('data.rating_distribution.1', 0);
    }

    public function test_published_customer_reviews_change_doctor_baseline_rating_summary(): void
    {
        $doctor = Doctor::factory()->create(['baseline_review_count' => 100]);
        Appointment::factory()
            ->count(10)
            ->for($doctor)
            ->create(['status' => Appointment::STATUS_COMPLETED])
            ->each(fn (Appointment $appointment): Review => Review::factory()->create([
                'appointment_id' => $appointment->id,
                'rating' => 1,
                'status' => Review::STATUS_PUBLISHED,
            ]));

        $this->getJson("/api/doctors/{$doctor->id}")
            ->assertOk()
            ->assertJsonPath('data.average_rating', 4.6)
            ->assertJsonPath('data.review_count', 110)
            ->assertJsonPath('data.rating_distribution.5', 100)
            ->assertJsonPath('data.rating_distribution.1', 10);
    }
}
