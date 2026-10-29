<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserProfileService
{
    public function __construct(private readonly RegisteredCustomerService $customerService) {}

    /** @param array{name: string, phone?: ?string} $data */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $user->update(array_intersect_key($data, array_flip(['name', 'phone'])));

            if ($user->isCustomer()) {
                $this->customerService->synchronizeFromUser($user->refresh());
            }

            return $user->refresh();
        }, 3);
    }
}
