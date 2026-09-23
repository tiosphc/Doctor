<?php

namespace App\Services;

use App\Models\Doctor;

class DoctorAccountCreationResult
{
    public function __construct(
        public readonly Doctor $doctor,
        public readonly bool $invitationQueued,
    ) {}
}
