<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_customer_foundation_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('customers', [
            'id',
            'customer_code',
            'user_id',
            'name',
            'primary_email',
            'normalized_email',
            'primary_phone',
            'normalized_phone',
            'verified_email_at',
            'verified_phone_at',
            'status',
            'source',
            'merged_into_customer_id',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('customer_identity_conflicts', [
            'id',
            'conflict_key',
            'source_type',
            'source_id',
            'reason_code',
            'status',
            'resolution',
            'resolution_customer_id',
            'resolved_by',
            'resolved_at',
            'resolution_note',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('customer_identity_conflict_candidates', [
            'conflict_id',
            'customer_id',
            'match_basis',
            'confidence',
            'created_at',
        ]));
        $this->assertTrue(Schema::hasColumns('customer_migration_map', [
            'id',
            'batch_key',
            'normalization_version',
            'source_type',
            'source_id',
            'customer_id',
            'decision',
            'conflict_id',
            'input_fingerprint',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('appointments', [
            'customer_id',
            'customer_name_snapshot',
            'customer_email_snapshot',
            'customer_phone_snapshot',
        ]));
    }

    public function test_customer_without_user_is_valid(): void
    {
        $customer = Customer::factory()->guest()->create();

        $this->assertModelExists($customer);
        $this->assertNull($customer->user_id);
    }

    public function test_database_rejects_two_customers_linked_to_the_same_user(): void
    {
        $user = User::factory()->customer()->create();
        Customer::factory()->withUser($user)->create();

        try {
            Customer::factory()->withUser($user)->create();
            $this->fail('The database accepted a duplicate Customer-to-User link.');
        } catch (QueryException) {
            $this->assertDatabaseCount('customers', 1);
        }
    }

    public function test_duplicate_normalized_email_is_allowed(): void
    {
        Customer::factory()->count(2)->create([
            'primary_email' => 'shared@example.test',
            'normalized_email' => 'shared@example.test',
        ]);

        $this->assertDatabaseCount('customers', 2);
    }

    public function test_duplicate_normalized_phone_is_allowed(): void
    {
        Customer::factory()->count(2)->create([
            'primary_phone' => '0901234567',
            'normalized_phone' => '+84901234567',
        ]);

        $this->assertDatabaseCount('customers', 2);
    }

    public function test_factory_supports_all_customer_statuses_and_merge_relationships(): void
    {
        $active = Customer::factory()->create();
        $inactive = Customer::factory()->inactive()->create();
        $merged = Customer::factory()->merged($active)->create();

        $this->assertSame(Customer::STATUS_ACTIVE, $active->status);
        $this->assertSame(Customer::STATUS_INACTIVE, $inactive->status);
        $this->assertSame(Customer::STATUS_MERGED, $merged->status);
        $this->assertTrue($merged->mergedInto->is($active));
        $this->assertTrue($active->mergedSources->contains($merged));
    }

    public function test_user_customer_and_appointment_relationships_work_without_changing_ownership(): void
    {
        $user = User::factory()->customer()->create();
        $customer = Customer::factory()->withUser($user)->create();
        $appointment = Appointment::factory()
            ->for($user)
            ->for($customer)
            ->create();

        $this->assertTrue($user->customer->is($customer));
        $this->assertTrue($customer->user->is($user));
        $this->assertTrue($customer->appointments->contains($appointment));
        $this->assertTrue($appointment->customer->is($customer));
        $this->assertSame($user->id, $appointment->user_id);
    }

    public function test_conflict_key_must_be_unique(): void
    {
        $attributes = [
            'conflict_key' => str_repeat('a', 64),
            'source_type' => 'appointment',
            'source_id' => 101,
            'reason_code' => 'EMAIL_MATCH_PHONE_MISMATCH',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('customer_identity_conflicts')->insert($attributes);

        try {
            DB::table('customer_identity_conflicts')->insert([
                ...$attributes,
                'source_id' => 102,
            ]);
            $this->fail('The database accepted a duplicate conflict key.');
        } catch (QueryException) {
            $this->assertDatabaseCount('customer_identity_conflicts', 1);
        }
    }

    public function test_conflict_candidate_tuple_must_be_unique(): void
    {
        $customer = Customer::factory()->create();
        $conflictId = $this->createConflict();
        $candidate = [
            'conflict_id' => $conflictId,
            'customer_id' => $customer->id,
            'match_basis' => 'email',
            'confidence' => 'medium',
        ];
        DB::table('customer_identity_conflict_candidates')->insert($candidate);

        try {
            DB::table('customer_identity_conflict_candidates')->insert($candidate);
            $this->fail('The database accepted a duplicate conflict candidate tuple.');
        } catch (QueryException) {
            $this->assertDatabaseCount('customer_identity_conflict_candidates', 1);
        }
    }

    public function test_migration_source_must_be_unique_across_batches(): void
    {
        $customer = Customer::factory()->create();
        $source = [
            'normalization_version' => 'v1',
            'source_type' => 'user',
            'source_id' => 401,
            'customer_id' => $customer->id,
            'decision' => 'CREATE_NEW',
            'input_fingerprint' => str_repeat('c', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('customer_migration_map')->insert([
            ...$source,
            'batch_key' => 'batch-001',
        ]);

        try {
            DB::table('customer_migration_map')->insert([
                ...$source,
                'batch_key' => 'batch-002',
            ]);
            $this->fail('The database accepted the same migration source twice.');
        } catch (QueryException) {
            $this->assertDatabaseCount('customer_migration_map', 1);
        }
    }

    public function test_existing_ownership_trigger_accepts_legacy_modes_and_rejects_mixed_ownership(): void
    {
        $user = User::factory()->customer()->create();
        $registered = Appointment::factory()->for($user)->create();
        $guest = Appointment::factory()->guest()->create();

        $this->assertNull($registered->customer_id);
        $this->assertNull($guest->customer_id);

        try {
            Appointment::factory()->guest()->create(['user_id' => $user->id]);
            $this->fail('The ownership trigger accepted mixed registered and guest ownership.');
        } catch (QueryException) {
            $this->assertDatabaseCount('appointments', 2);
        }
    }

    private function createConflict(): int
    {
        return DB::table('customer_identity_conflicts')->insertGetId([
            'conflict_key' => str_repeat('b', 64),
            'source_type' => 'appointment',
            'source_id' => 201,
            'reason_code' => 'DUPLICATE_CONTACT',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
