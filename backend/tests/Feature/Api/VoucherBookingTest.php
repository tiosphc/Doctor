<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VoucherBookingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_customer_uses_own_active_voucher_with_server_calculated_price_snapshot(): void
    {
        [$customer, $doctor, $service, $voucher] = $this->bookingContext();
        Sanctum::actingAs($customer);

        $this->postJson('/api/appointments', [
            ...$this->payload($doctor, $service),
            'voucher_id' => $voucher->id,
        ])->assertCreated()
            ->assertJsonPath('data.pricing.original_price', '500000.00')
            ->assertJsonPath('data.pricing.discount_amount', '25000.00')
            ->assertJsonPath('data.pricing.final_price', '475000.00')
            ->assertJsonPath('data.voucher.id', $voucher->id);

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'status' => Voucher::STATUS_USED]);
        $this->assertDatabaseHas('appointments', [
            'voucher_id' => $voucher->id, 'original_price' => 500000,
            'discount_amount' => 25000, 'final_price' => 475000,
        ]);
    }

    public function test_loyalty_voucher_applies_twenty_five_percent_without_stacking(): void
    {
        [$customer, $doctor, $service, $voucher] = $this->bookingContext();
        $voucher->update([
            'source' => Voucher::SOURCE_LOYALTY_MILESTONE,
            'source_id' => 10,
            'value' => 25,
        ]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/appointments', [
            ...$this->payload($doctor, $service),
            'voucher_id' => $voucher->id,
            'voucher_ids' => [$voucher->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['voucher_ids']);

        $this->postJson('/api/appointments', [
            ...$this->payload($doctor, $service),
            'voucher_id' => $voucher->id,
        ])->assertCreated()
            ->assertJsonPath('data.pricing.discount_amount', '125000.00')
            ->assertJsonPath('data.pricing.final_price', '375000.00')
            ->assertJsonPath('data.voucher.source', Voucher::SOURCE_LOYALTY_MILESTONE)
            ->assertJsonPath('data.voucher.milestone', 10);

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->id,
            'status' => Voucher::STATUS_USED,
        ]);
    }

    public function test_customer_can_redeem_a_general_admin_voucher_once(): void
    {
        [$customer, $doctor, $service] = $this->bookingContext();
        $voucher = Voucher::factory()->admin()->create([
            'value' => 10,
            'expires_at' => now()->addDays(30),
        ]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/appointments', [
            ...$this->payload($doctor, $service),
            'voucher_id' => $voucher->id,
        ])->assertCreated()
            ->assertJsonPath('data.pricing.discount_amount', '50000.00')
            ->assertJsonPath('data.pricing.final_price', '450000.00')
            ->assertJsonPath('data.voucher.source', Voucher::SOURCE_ADMIN);

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->id,
            'user_id' => null,
            'status' => Voucher::STATUS_USED,
        ]);
    }

    public function test_customer_cannot_control_price_or_use_another_customers_voucher(): void
    {
        [$customer, $doctor, $service] = $this->bookingContext();
        $otherVoucher = Voucher::factory()->create(['expires_at' => now()->addDays(10)]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/appointments', [
            ...$this->payload($doctor, $service),
            'voucher_id' => $otherVoucher->id,
        ])->assertForbidden();

        $this->postJson('/api/appointments', [
            ...$this->payload($doctor, $service),
            'final_price' => 1,
            'discount_percent' => 99,
        ])->assertUnprocessable()->assertJsonValidationErrors(['final_price', 'discount_percent']);
        $this->assertDatabaseMissing('appointments', ['voucher_id' => $otherVoucher->id]);
    }

    public function test_expired_and_used_vouchers_are_rejected(): void
    {
        [$customer, $doctor, $service, $voucher] = $this->bookingContext();
        Sanctum::actingAs($customer);
        $voucher->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/appointments', [...$this->payload($doctor, $service), 'voucher_id' => $voucher->id])
            ->assertConflict()->assertJsonPath('message', 'Voucher này đã hết hạn.');

        $voucher->update(['expires_at' => now()->addDay(), 'status' => Voucher::STATUS_USED, 'used_at' => now()]);
        $this->postJson('/api/appointments', [...$this->payload($doctor, $service), 'voucher_id' => $voucher->id])
            ->assertConflict()->assertJsonPath('message', 'Voucher này đã được sử dụng.');
        $this->assertDatabaseMissing('appointments', ['voucher_id' => $voucher->id]);
    }

    public function test_failed_booking_does_not_consume_voucher_and_successful_booking_prevents_reuse(): void
    {
        [$customer, $doctor, $service, $voucher] = $this->bookingContext();
        Sanctum::actingAs($customer);

        $this->postJson('/api/appointments', [
            ...$this->payload($doctor, $service), 'start_time' => '13:00', 'voucher_id' => $voucher->id,
        ])->assertConflict();
        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'status' => Voucher::STATUS_ACTIVE, 'used_at' => null]);

        $this->postJson('/api/appointments', [...$this->payload($doctor, $service), 'voucher_id' => $voucher->id])->assertCreated();
        $this->postJson('/api/appointments', [
            ...$this->payload($doctor, $service), 'start_time' => '10:00', 'voucher_id' => $voucher->id,
        ])->assertConflict();
        $this->assertSame(1, Appointment::query()->where('voucher_id', $voucher->id)->count());
    }

    public function test_cancelling_future_booking_restores_unexpired_voucher_once(): void
    {
        [$customer, $doctor, $service, $voucher] = $this->bookingContext();
        Sanctum::actingAs($customer);
        $appointmentId = $this->postJson('/api/appointments', [
            ...$this->payload($doctor, $service), 'voucher_id' => $voucher->id,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/my-appointments/{$appointmentId}/cancel")->assertOk();
        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'status' => Voucher::STATUS_ACTIVE, 'used_at' => null]);
        $this->patchJson("/api/my-appointments/{$appointmentId}/cancel")->assertConflict();
        $this->assertDatabaseCount('vouchers', 1);
    }

    public function test_reschedule_keeps_same_voucher_and_price_snapshot(): void
    {
        [$customer, $doctor, $service, $voucher] = $this->bookingContext();
        DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 2, 'start_time' => '09:00:00', 'end_time' => '12:00:00',
        ]);
        Sanctum::actingAs($customer);
        $appointmentId = $this->postJson('/api/appointments', [
            ...$this->payload($doctor, $service), 'voucher_id' => $voucher->id,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/my-appointments/{$appointmentId}/reschedule", [
            'appointment_date' => '2026-09-22', 'start_time' => '10:00',
        ])->assertOk();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointmentId, 'voucher_id' => $voucher->id,
            'original_price' => 500000, 'discount_amount' => 25000, 'final_price' => 475000,
        ]);
        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'status' => Voucher::STATUS_USED]);
    }

    public function test_cancellation_after_original_expiry_leaves_voucher_expired(): void
    {
        [$customer, $doctor, $service, $voucher] = $this->bookingContext();
        $voucher->update(['expires_at' => now()->addMinutes(30)]);
        Sanctum::actingAs($customer);
        $appointmentId = $this->postJson('/api/appointments', [
            ...$this->payload($doctor, $service), 'voucher_id' => $voucher->id,
        ])->assertCreated()->json('data.id');
        $this->travel(31)->minutes();

        $this->patchJson("/api/my-appointments/{$appointmentId}/cancel")->assertOk();

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->id, 'status' => Voucher::STATUS_EXPIRED, 'used_at' => null,
        ]);
    }

    /** @return array{User, Doctor, Service, Voucher} */
    private function bookingContext(): array
    {
        $this->travelTo('2026-09-20 09:00:00');
        $customer = User::factory()->customer()->create();
        $doctor = Doctor::factory()->create();
        $service = Service::factory()->create(['duration' => 60, 'price' => 500000]);
        $doctor->services()->attach($service);
        DoctorSchedule::factory()->for($doctor)->create([
            'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '12:00:00',
        ]);
        $voucher = Voucher::factory()->for($customer)->create(['value' => 5, 'expires_at' => now()->addDays(30)]);

        return [$customer, $doctor, $service, $voucher];
    }

    /** @return array<string, mixed> */
    private function payload(Doctor $doctor, Service $service): array
    {
        return [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'appointment_date' => '2026-09-21',
            'start_time' => '09:00',
        ];
    }
}
