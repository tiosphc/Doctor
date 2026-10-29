<?php

namespace App\Services;

class RegisteredCustomerBackfillReport
{
    public int $scannedUsers = 0;

    public int $eligibleUsers = 0;

    public int $processedEligibleUsers = 0;

    public int $customersToCreate = 0;

    public int $customersCreated = 0;

    public int $existingLinkedCustomers = 0;

    public int $appointmentsLinkable = 0;

    public int $appointmentsLinked = 0;

    public int $conflicts = 0;

    public int $skippedRecords = 0;

    public int $invalidRecords = 0;

    /** @var array<string, int> */
    public array $reconciliation = [];

    /** @return array<string, int> */
    public function metrics(): array
    {
        return [
            'scanned_users' => $this->scannedUsers,
            'eligible_registered_users' => $this->eligibleUsers,
            'processed_eligible_users' => $this->processedEligibleUsers,
            'customers_to_create' => $this->customersToCreate,
            'customers_created' => $this->customersCreated,
            'existing_linked_customers' => $this->existingLinkedCustomers,
            'appointments_linkable' => $this->appointmentsLinkable,
            'appointments_linked' => $this->appointmentsLinked,
            'conflicts' => $this->conflicts,
            'skipped_records' => $this->skippedRecords,
            'invalid_records' => $this->invalidRecords,
        ];
    }

    public function hasUnexplainedDifferences(): bool
    {
        return collect([
            'eligible_users_without_customer',
            'duplicate_customer_user_links',
            'customers_linked_to_missing_users',
            'registered_appointments_unlinked',
            'appointment_owner_mismatches',
            'unexpected_guest_appointment_links',
            'registered_customers_without_code',
            'duplicate_customer_codes',
            'inconsistent_migration_maps',
            'unexpected_orphan_registered_customers',
        ])->contains(fn (string $key): bool => ($this->reconciliation[$key] ?? 0) > 0);
    }
}
