<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Doctor;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\LoyaltyMilestoneRewardNotification;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoyaltyControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_summary_counts_only_registered_customer_completed_appointments(): void
    {
        $customer = User::factory()->customer()->create();
        foreach (Appointment::STATUSES as $status) {
            Appointment::factory()->for($customer)->create(['status' => $status]);
        }
        Appointment::factory()->guest()->create(['status' => Appointment::STATUS_COMPLETED]);
        Sanctum::actingAs($customer);

        $this->getJson('/api/my-loyalty')
            ->assertOk()
            ->assertJsonPath('data.completed_visits', 1)
            ->assertJsonPath('data.next_milestone.visits', 10)
            ->assertJsonPath('data.next_milestone.reward_type', Voucher::TYPE_PERCENTAGE)
            ->assertJsonPath('data.next_milestone.reward_value', 25)
            ->assertJsonPath('data.next_milestone.remaining_visits', 9)
            ->assertJsonCount(0, 'data.achieved_milestones');
    }

    public function test_final_completion_creates_one_loyalty_voucher_and_notifies_customer(): void
    {
        Notification::fake();
        $this->travelTo('2026-09-21 10:00:00');
        $customer = User::factory()->customer()->create();
        Appointment::factory()->for($customer)->count(9)->create([
            'status' => Appointment::STATUS_COMPLETED,
        ]);
        $appointment = Appointment::factory()->for($customer)->create([
            'status' => Appointment::STATUS_TREATMENT_DONE,
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/admin/appointments/{$appointment->id}/status", [
            'status' => Appointment::STATUS_COMPLETED,
        ])->assertOk()->assertJsonPath('data.status', Appointment::STATUS_COMPLETED);

        $voucher = Voucher::query()
            ->whereBelongsTo($customer)
            ->where('source', Voucher::SOURCE_LOYALTY_MILESTONE)
            ->sole();
        $this->assertSame(Voucher::TYPE_PERCENTAGE, $voucher->type);
        $this->assertSame('25.00', $voucher->value);
        $this->assertSame(10, (int) $voucher->source_id);
        $this->assertSame(Voucher::STATUS_ACTIVE, $voucher->status);
        $this->assertSame('2026-10-21', $voucher->expires_at->toDateString());
        Notification::assertSentTo(
            $customer,
            LoyaltyMilestoneRewardNotification::class,
            function (LoyaltyMilestoneRewardNotification $notification) use ($customer): bool {
                $data = $notification->toArray($customer);

                return $data['title'] === 'Bạn vừa nhận Voucher 25%'
                    && $data['source'] === Voucher::SOURCE_LOYALTY_MILESTONE
                    && $data['data']['milestone'] === 10
                    && str_contains($data['action_url'], '/account/vouchers');
            },
        );
    }

    public function test_doctor_treatment_completion_does_not_issue_loyalty_reward(): void
    {
        Notification::fake();
        $customer = User::factory()->customer()->create();
        Appointment::factory()->for($customer)->count(9)->create([
            'status' => Appointment::STATUS_COMPLETED,
        ]);
        $doctorUser = User::factory()->doctor()->create();
        $doctor = Doctor::factory()->create(['user_id' => $doctorUser->id]);
        $appointment = Appointment::factory()->for($customer)->for($doctor)->create([
            'status' => Appointment::STATUS_IN_PROGRESS,
        ]);
        Sanctum::actingAs($doctorUser);

        $this->patchJson("/api/doctor/appointments/{$appointment->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', Appointment::STATUS_TREATMENT_DONE);

        $this->assertDatabaseMissing('vouchers', [
            'user_id' => $customer->id,
            'source' => Voucher::SOURCE_LOYALTY_MILESTONE,
        ]);
        Notification::assertNotSentTo($customer, LoyaltyMilestoneRewardNotification::class);
    }

    public function test_later_completed_visits_and_repeated_evaluation_do_not_duplicate_milestone(): void
    {
        $customer = User::factory()->customer()->create();
        Appointment::factory()->for($customer)->count(12)->create([
            'status' => Appointment::STATUS_COMPLETED,
        ]);
        $loyaltyService = app(LoyaltyService::class);

        $loyaltyService->evaluateAfterCompletion($customer);
        $loyaltyService->evaluateAfterCompletion($customer);

        $this->assertSame(1, Voucher::query()
            ->whereBelongsTo($customer)
            ->where('source', Voucher::SOURCE_LOYALTY_MILESTONE)
            ->where('source_id', 10)
            ->count());
    }

    public function test_review_and_loyalty_rewards_coexist_in_the_same_wallet(): void
    {
        $customer = User::factory()->customer()->create();
        $reviewVoucher = Voucher::factory()->for($customer)->create();
        Appointment::factory()->for($customer)->count(10)->create([
            'status' => Appointment::STATUS_COMPLETED,
        ]);

        app(LoyaltyService::class)->evaluateAfterCompletion($customer);

        $this->assertDatabaseHas('vouchers', [
            'id' => $reviewVoucher->id,
            'source' => Voucher::SOURCE_REVIEW_REWARD,
            'status' => Voucher::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('vouchers', [
            'user_id' => $customer->id,
            'source' => Voucher::SOURCE_LOYALTY_MILESTONE,
            'source_id' => 10,
            'value' => 25,
        ]);
        Sanctum::actingAs($customer);
        $this->getJson('/api/my-vouchers')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_historical_customer_receives_unissued_milestone_on_next_completion(): void
    {
        $customer = User::factory()->customer()->create();
        Appointment::factory()->for($customer)->count(10)->create([
            'status' => Appointment::STATUS_COMPLETED,
        ]);
        $appointment = Appointment::factory()->for($customer)->create([
            'status' => Appointment::STATUS_TREATMENT_DONE,
        ]);
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->patchJson("/api/receptionist/appointments/{$appointment->id}/complete")
            ->assertOk();

        $this->assertDatabaseHas('vouchers', [
            'user_id' => $customer->id,
            'source' => Voucher::SOURCE_LOYALTY_MILESTONE,
            'source_id' => 10,
        ]);
    }

    public function test_loyalty_summary_requires_customer_authentication_and_admin_detail_is_protected(): void
    {
        $customer = User::factory()->customer()->create();
        $customerProfile = Customer::factory()->withUser($customer)->create();

        $this->getJson('/api/my-loyalty')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/my-loyalty')->assertForbidden();
        $this->getJson("/api/admin/customers/{$customerProfile->id}")
            ->assertOk()
            ->assertJsonPath('data.loyalty.completed_visits', 0)
            ->assertJsonPath('data.statistics.total_appointments', 0);

        Sanctum::actingAs($customer);
        $this->getJson("/api/admin/customers/{$customerProfile->id}")->assertForbidden();
    }
}
