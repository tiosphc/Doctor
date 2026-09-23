<?php

namespace App\Services;

use App\Models\Customer;

class RegisteredCustomerResult
{
    public function __construct(
        public readonly Customer $customer,
        public readonly string $decision,
        public readonly ?int $conflictId,
        public readonly bool $created,
        public readonly bool $invalidData,
    ) {}
}
