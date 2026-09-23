<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\User;
use App\Support\CustomerCode;
use App\Support\CustomerIdentityNormalizer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegisteredCustomerBackfillCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dry_run_reports_work_without_mutating_business_data(): void
    {
        $user = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($user)->create();

        $this->artisan('customers:backfill-registered', ['--dry-run' => true])
            ->expectsOutputToContain('customers_to_create')
            ->expectsOutputToContain('appointments_linkable')
            ->assertSuccessful();

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('customer_migration_map', 0);
        $this->assertDatabaseCount('customer_identity_conflicts', 0);
        $this->assertNull($appointment->refresh()->customer_id);
    }

    public function test_backfill_creates_one_canonical_customer_and_links_registered_appointments(): void
    {
        $user = User::factory()->customer()->create([
            'name' => '  Customer   One ',
            'email' => 'Customer.One@Example.com',
            'phone' => '0912 345 678',
            'email_verified_at' => '2026-09-20 09:00:00',
        ]);
        $appointment = Appointment::factory()->for($user)->create();

        $this->artisan('customers:backfill-registered')->assertSuccessful();

        $customer = Customer::query()->whereBelongsTo($user)->sole();
        $this->assertSame(CustomerCode::fromId($customer->id), $customer->customer_code);
        $this->assertSame('Customer One', $customer->name);
        $this->assertSame('Customer.One@Example.com', $customer->primary_email);
        $this->assertSame('customer.one@example.com', $customer->normalized_email);
        $this->assertSame('+84912345678', $customer->normalized_phone);
        $this->assertSame('2026-09-20 09:00:00', $customer->verified_email_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'customer_name_snapshot' => 'Customer One',
            'customer_email_snapshot' => 'Customer.One@Example.com',
            'customer_phone_snapshot' => '0912 345 678',
        ]);
        $this->assertDatabaseHas('customer_migration_map', [
            'normalization_version' => CustomerIdentityNormalizer::VERSION,
            'source_type' => 'user',
            'source_id' => $user->id,
            'customer_id' => $customer->id,
            'decision' => 'CREATE_NEW',
        ]);
    }

    public function test_rerun_reuses_customer_code_mapping_and_correct_appointment_link(): void
    {
        $user = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($user)->create();

        $this->artisan('customers:backfill-registered')->assertSuccessful();
        $customer = Customer::query()->whereBelongsTo($user)->sole();
        $code = $customer->customer_code;

        $this->artisan('customers:backfill-registered')->assertSuccessful();

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('customer_migration_map', 1);
        $this->assertSame($code, $customer->refresh()->customer_code);
        $this->assertSame($customer->id, $appointment->refresh()->customer_id);
    }

    public function test_existing_correct_customer_is_reused_and_receives_stable_code(): void
    {
        $user = User::factory()->customer()->create(['phone' => null]);
        $customer = Customer::factory()->withUser($user)->create(['customer_code' => null]);

        $this->artisan('customers:backfill-registered')->assertSuccessful();

        $this->assertDatabaseCount('customers', 1);
        $this->assertSame(CustomerCode::fromId($customer->id), $customer->refresh()->customer_code);
        $this->assertDatabaseHas('customer_migration_map', [
            'source_type' => 'user',
            'source_id' => $user->id,
            'customer_id' => $customer->id,
            'decision' => 'AUTO_MATCH',
        ]);
    }

    public function test_duplicate_normalized_email_creates_separate_customer_and_persists_one_conflict(): void
    {
        $existing = Customer::factory()->create([
            'primary_email' => 'shared@example.test',
            'normalized_email' => 'shared@example.test',
            'primary_phone' => null,
            'normalized_phone' => null,
        ]);
        $user = User::factory()->customer()->create([
            'email' => 'SHARED@example.test',
            'phone' => null,
        ]);

        $this->artisan('customers:backfill-registered')->assertSuccessful();
        $this->artisan('customers:backfill-registered')->assertSuccessful();

        $customer = Customer::query()->whereBelongsTo($user)->sole();
        $this->assertFalse($customer->is($existing));
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('customer_identity_conflicts', 1);
        $this->assertDatabaseHas('customer_identity_conflict_candidates', [
            'customer_id' => $existing->id,
            'match_basis' => 'email',
        ]);
        $this->assertDatabaseHas('customer_migration_map', [
            'source_id' => $user->id,
            'customer_id' => $customer->id,
            'decision' => 'REQUIRES_REVIEW',
        ]);
    }

    public function test_duplicate_normalized_phone_never_auto_merges_customers(): void
    {
        $existing = Customer::factory()->create([
            'primary_email' => null,
            'normalized_email' => null,
            'primary_phone' => '0912345678',
            'normalized_phone' => '+84912345678',
        ]);
        $user = User::factory()->customer()->create([
            'phone' => '+84 912 345 678',
        ]);

        $this->artisan('customers:backfill-registered')->assertSuccessful();

        $customer = Customer::query()->whereBelongsTo($user)->sole();
        $this->assertFalse($customer->is($existing));
        $this->assertDatabaseHas('customer_identity_conflict_candidates', [
            'customer_id' => $existing->id,
            'match_basis' => 'phone',
        ]);
    }

    public function test_conflicting_appointment_customer_is_reported_and_not_overwritten(): void
    {
        $user = User::factory()->customer()->create();
        $expected = Customer::factory()->withUser($user)->create();
        $unexpected = Customer::factory()->guest()->create();
        $appointment = Appointment::factory()
            ->for($user)
            ->for($unexpected)
            ->create();

        $this->artisan('customers:backfill-registered')->assertFailed();

        $this->assertSame($unexpected->id, $appointment->refresh()->customer_id);
        $this->assertDatabaseHas('customer_identity_conflicts', [
            'source_type' => 'appointment',
            'source_id' => $appointment->id,
            'reason_code' => 'APPOINTMENT_CUSTOMER_MISMATCH',
        ]);
        $this->assertDatabaseHas('customer_identity_conflict_candidates', [
            'customer_id' => $expected->id,
            'match_basis' => 'expected_user_customer',
        ]);
    }

    public function test_guest_appointments_remain_unchanged_even_when_contacts_match_registered_user(): void
    {
        $user = User::factory()->customer()->create([
            'email' => 'same@example.test',
            'phone' => '0912345678',
        ]);
        $guest = Appointment::factory()->guest()->create([
            'guest_name' => $user->name,
            'guest_email' => 'same@example.test',
            'guest_phone' => '0912345678',
        ]);

        $this->artisan('customers:backfill-registered')->assertSuccessful();

        $guest->refresh();
        $this->assertNull($guest->user_id);
        $this->assertNull($guest->customer_id);
        $this->assertSame('same@example.test', $guest->guest_email);
        $this->assertSame('0912345678', $guest->guest_phone);
    }

    public function test_limited_canary_execution_resumes_without_duplicates(): void
    {
        $users = User::factory()->customer()->count(3)->create();
        $users->each(fn (User $user) => Appointment::factory()->for($user)->create());

        $this->artisan('customers:backfill-registered', ['--limit' => 1, '--batch' => 'canary-1'])
            ->assertSuccessful();
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('customer_migration_map', 1);

        $this->artisan('customers:backfill-registered', ['--batch' => 'resume-1'])
            ->assertSuccessful();

        $this->assertDatabaseCount('customers', 3);
        $this->assertDatabaseCount('customer_migration_map', 3);
        $this->assertSame(3, Appointment::query()->whereNotNull('customer_id')->count());
    }

    public function test_invalid_phone_is_preserved_but_not_normalized_and_is_reported_once(): void
    {
        $user = User::factory()->customer()->create(['phone' => '0912-EXT-123']);

        $this->artisan('customers:backfill-registered')->assertSuccessful();
        $this->artisan('customers:backfill-registered')->assertSuccessful();

        $customer = Customer::query()->whereBelongsTo($user)->sole();
        $this->assertSame('0912-EXT-123', $customer->primary_phone);
        $this->assertNull($customer->normalized_phone);
        $this->assertDatabaseCount('customer_identity_conflicts', 1);
        $this->assertDatabaseHas('customer_identity_conflicts', [
            'source_type' => 'user',
            'source_id' => $user->id,
            'reason_code' => 'INVALID_REGISTERED_CONTACT',
        ]);
    }

    public function test_reconciliation_reports_clean_expected_actual_and_difference_after_backfill(): void
    {
        $user = User::factory()->customer()->create();
        Appointment::factory()->for($user)->create();

        $this->artisan('customers:backfill-registered')->assertSuccessful();

        $this->artisan('customers:backfill-registered', ['--reconcile-only' => true])
            ->expectsOutputToContain('eligible_registered_users_expected')
            ->expectsOutputToContain('canonical_registered_customers_difference')
            ->expectsOutputToContain('registered_appointments_difference')
            ->assertSuccessful();
        $this->assertSame(0, DB::table('customer_identity_conflicts')->count());
    }
}
