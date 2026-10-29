<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\User;
use App\Support\CustomerIdentityNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RegisteredCustomerBackfillService
{
    public function __construct(private readonly RegisteredCustomerService $customerService) {}

    public function run(
        bool $dryRun,
        int $chunkSize,
        ?int $limit,
        string $batchKey,
    ): RegisteredCustomerBackfillReport {
        $report = new RegisteredCustomerBackfillReport;
        $report->scannedUsers = User::query()->count();
        $report->eligibleUsers = $this->eligibleUsersQuery()->count();
        $remaining = $limit;

        $this->eligibleUsersQuery()
            ->select(['id', 'name', 'email', 'phone', 'role', 'email_verified_at'])
            ->chunkById($chunkSize, function ($users) use ($dryRun, $batchKey, $report, &$remaining): bool {
                foreach ($users as $user) {
                    if ($remaining !== null && $remaining <= 0) {
                        return false;
                    }

                    $report->processedEligibleUsers++;

                    if ($dryRun) {
                        $this->previewUser($user, $report);
                    } else {
                        $this->processUser($user->id, $batchKey, $report);
                    }

                    if ($remaining !== null) {
                        $remaining--;
                    }
                }

                return $remaining === null || $remaining > 0;
            });

        $report->reconciliation = $this->reconcile();

        return $report;
    }

    /** @return array<string, int> */
    public function reconcile(): array
    {
        $eligibleUsers = $this->eligibleUsersQuery()->count();
        $registeredAppointments = Appointment::query()
            ->whereHas('user', fn (Builder $query): Builder => $query->where('role', User::ROLE_CUSTOMER))
            ->count();
        $canonicalCustomers = Customer::query()
            ->whereHas('user', fn (Builder $query): Builder => $query->where('role', User::ROLE_CUSTOMER))
            ->count();
        $correctlyLinkedAppointments = Appointment::query()
            ->join('customers', 'customers.id', '=', 'appointments.customer_id')
            ->join('users', 'users.id', '=', 'appointments.user_id')
            ->where('users.role', User::ROLE_CUSTOMER)
            ->whereColumn('customers.user_id', 'appointments.user_id')
            ->count();
        $normalizedEmailCollisions = DB::query()
            ->fromSub(
                Customer::query()
                    ->select('normalized_email')
                    ->whereNotNull('normalized_email')
                    ->groupBy('normalized_email')
                    ->havingRaw('COUNT(*) > 1'),
                'email_collisions',
            )
            ->count();
        $normalizedPhoneCollisions = DB::query()
            ->fromSub(
                Customer::query()
                    ->select('normalized_phone')
                    ->whereNotNull('normalized_phone')
                    ->groupBy('normalized_phone')
                    ->havingRaw('COUNT(*) > 1'),
                'phone_collisions',
            )
            ->count();

        return [
            'eligible_registered_users_expected' => $eligibleUsers,
            'canonical_registered_customers_actual' => $canonicalCustomers,
            'canonical_registered_customers_difference' => $eligibleUsers - $canonicalCustomers,
            'eligible_users_without_customer' => User::query()
                ->where('role', User::ROLE_CUSTOMER)
                ->whereDoesntHave('customer')
                ->count(),
            'duplicate_customer_user_links' => DB::query()
                ->fromSub(
                    Customer::query()
                        ->select('user_id')
                        ->whereNotNull('user_id')
                        ->groupBy('user_id')
                        ->havingRaw('COUNT(*) > 1'),
                    'duplicate_user_links',
                )
                ->count(),
            'customers_linked_to_missing_users' => Customer::query()
                ->whereNotNull('user_id')
                ->whereDoesntHave('user')
                ->count(),
            'registered_appointments_expected' => $registeredAppointments,
            'registered_appointments_correctly_linked_actual' => $correctlyLinkedAppointments,
            'registered_appointments_difference' => $registeredAppointments - $correctlyLinkedAppointments,
            'registered_appointments_unlinked' => Appointment::query()
                ->whereHas('user', fn (Builder $query): Builder => $query->where('role', User::ROLE_CUSTOMER))
                ->whereNull('customer_id')
                ->count(),
            'appointment_owner_mismatches' => Appointment::query()
                ->join('customers', 'customers.id', '=', 'appointments.customer_id')
                ->whereNotNull('appointments.user_id')
                ->where(function ($query): void {
                    $query->whereNull('customers.user_id')
                        ->orWhereColumn('customers.user_id', '!=', 'appointments.user_id');
                })
                ->count(),
            'unexpected_guest_appointment_links' => Appointment::query()
                ->whereNull('user_id')
                ->whereNotNull('customer_id')
                ->count(),
            'registered_customers_without_code' => Customer::query()
                ->whereNotNull('user_id')
                ->whereNull('customer_code')
                ->count(),
            'duplicate_customer_codes' => DB::query()
                ->fromSub(
                    Customer::query()
                        ->select('customer_code')
                        ->whereNotNull('customer_code')
                        ->groupBy('customer_code')
                        ->havingRaw('COUNT(*) > 1'),
                    'duplicate_customer_codes',
                )
                ->count(),
            'normalized_email_collision_groups' => $normalizedEmailCollisions,
            'normalized_phone_collision_groups' => $normalizedPhoneCollisions,
            'identity_conflicts' => DB::table('customer_identity_conflicts')->count(),
            'migration_maps' => DB::table('customer_migration_map')
                ->where('source_type', 'user')
                ->count(),
            'inconsistent_migration_maps' => DB::table('customer_migration_map as maps')
                ->leftJoin('customers', 'customers.id', '=', 'maps.customer_id')
                ->where('maps.source_type', 'user')
                ->where(function ($query): void {
                    $query->whereNull('customers.id')
                        ->orWhereColumn('customers.user_id', '!=', 'maps.source_id');
                })
                ->count(),
            'unexpected_orphan_registered_customers' => Customer::query()
                ->where('source', Customer::SOURCE_REGISTERED)
                ->whereNull('user_id')
                ->count(),
        ];
    }

    /** @return Builder<User> */
    private function eligibleUsersQuery(): Builder
    {
        return User::query()
            ->where('role', User::ROLE_CUSTOMER)
            ->orderBy('id');
    }

    private function previewUser(User $user, RegisteredCustomerBackfillReport $report): void
    {
        $customer = Customer::query()->where('user_id', $user->id)->first();
        $inspection = $this->customerService->inspect($user, $customer);

        if ($customer === null) {
            $report->customersToCreate++;
        } else {
            $report->existingLinkedCustomers++;
        }

        $linkable = Appointment::query()
            ->where('user_id', $user->id)
            ->whereNull('customer_id')
            ->count();
        $mismatches = Appointment::query()
            ->where('user_id', $user->id)
            ->whereNotNull('customer_id')
            ->when($customer !== null, fn (Builder $query): Builder => $query->where('customer_id', '!=', $customer->id))
            ->when($customer === null, fn (Builder $query): Builder => $query)
            ->count();

        $report->appointmentsLinkable += $linkable;

        if ($inspection['invalid_data']) {
            $report->invalidRecords++;
        }

        if ($inspection['invalid_data'] || $inspection['candidates'] !== []) {
            $report->conflicts++;
        }

        $report->conflicts += $mismatches;
    }

    private function processUser(int $userId, string $batchKey, RegisteredCustomerBackfillReport $report): void
    {
        DB::transaction(function () use ($userId, $batchKey, $report): void {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();

            if ($user === null || ! $user->isCustomer()) {
                $report->skippedRecords++;

                return;
            }

            $map = DB::table('customer_migration_map')
                ->where('source_type', 'user')
                ->where('source_id', $user->id)
                ->lockForUpdate()
                ->first();
            $linkedCustomer = Customer::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($map !== null && ($linkedCustomer === null || (int) $map->customer_id !== $linkedCustomer->id)) {
                $candidateRows = collect([$map->customer_id, $linkedCustomer?->id])
                    ->filter()
                    ->unique()
                    ->map(fn (mixed $customerId): array => [
                        'customer_id' => (int) $customerId,
                        'match_basis' => 'migration_map',
                        'confidence' => 'high',
                    ])
                    ->values()
                    ->all();
                $this->customerService->persistConflict(
                    'user',
                    $user->id,
                    'MIGRATION_MAP_CUSTOMER_MISMATCH',
                    $candidateRows,
                );
                $report->conflicts++;
                $report->skippedRecords++;

                return;
            }

            $inspection = $this->customerService->inspect($user, $linkedCustomer);

            if ($inspection['identity']['name'] === null) {
                $this->customerService->persistConflict(
                    'user',
                    $user->id,
                    'INVALID_REGISTERED_NAME',
                );
                $report->conflicts++;
                $report->invalidRecords++;
                $report->skippedRecords++;

                return;
            }

            $resolution = $this->customerService->ensureForLockedUser($user);
            $customer = $resolution->customer;

            if ($resolution->created) {
                $report->customersCreated++;
            } else {
                $report->existingLinkedCustomers++;
            }

            if ($resolution->invalidData) {
                $report->invalidRecords++;
            }

            if ($resolution->conflictId !== null) {
                $report->conflicts++;
            }

            $mismatchedAppointments = Appointment::query()
                ->where('user_id', $user->id)
                ->whereNotNull('customer_id')
                ->where('customer_id', '!=', $customer->id)
                ->orderBy('id')
                ->get(['id', 'customer_id']);

            foreach ($mismatchedAppointments as $appointment) {
                $this->customerService->persistConflict(
                    'appointment',
                    $appointment->id,
                    'APPOINTMENT_CUSTOMER_MISMATCH',
                    [
                        [
                            'customer_id' => $customer->id,
                            'match_basis' => 'expected_user_customer',
                            'confidence' => 'high',
                        ],
                        [
                            'customer_id' => (int) $appointment->customer_id,
                            'match_basis' => 'current_customer',
                            'confidence' => 'high',
                        ],
                    ],
                );
                $report->conflicts++;
            }

            $appointmentQuery = Appointment::query()
                ->where('user_id', $user->id)
                ->whereNull('customer_id');
            $linkable = $appointmentQuery->count();
            $appointmentQuery->update(['customer_id' => $customer->id]);
            $report->appointmentsLinkable += $linkable;
            $report->appointmentsLinked += $linkable;

            $correctAppointments = Appointment::query()
                ->where('user_id', $user->id)
                ->where('customer_id', $customer->id);
            $correctAppointments->clone()
                ->whereNull('customer_name_snapshot')
                ->update(['customer_name_snapshot' => $customer->name]);

            if ($customer->primary_email !== null) {
                $correctAppointments->clone()
                    ->whereNull('customer_email_snapshot')
                    ->update(['customer_email_snapshot' => $customer->primary_email]);
            }

            if ($customer->primary_phone !== null) {
                $correctAppointments->clone()
                    ->whereNull('customer_phone_snapshot')
                    ->update(['customer_phone_snapshot' => $customer->primary_phone]);
            }

            if ($map === null) {
                DB::table('customer_migration_map')->insertOrIgnore([
                    'batch_key' => $batchKey,
                    'normalization_version' => CustomerIdentityNormalizer::VERSION,
                    'source_type' => 'user',
                    'source_id' => $user->id,
                    'customer_id' => $customer->id,
                    'decision' => $resolution->decision,
                    'conflict_id' => $resolution->conflictId,
                    'input_fingerprint' => $this->customerService->inputFingerprint($user),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);
    }
}
