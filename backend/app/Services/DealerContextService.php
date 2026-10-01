<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class DealerContextService
{
    /** @return Collection<int, DealerAccountUser> */
    public function activeMemberships(User $user): Collection
    {
        return $user->dealerMemberships()->where('status', DealerAccountUser::STATUS_ACTIVE)
            ->whereHas('account', fn ($query) => $query->where('status', DealerAccount::STATUS_ACTIVE))
            ->with('account')->orderBy('dealer_account_id')->get();
    }

    public function primaryAccount(User $user): ?DealerAccount
    {
        return $this->activeMemberships($user)->first()?->account;
    }

    public function resolve(User $user, DealerAccount $account): DealerAccountUser
    {
        return $user->dealerMemberships()->where('dealer_account_id', $account->id)
            ->where('status', DealerAccountUser::STATUS_ACTIVE)
            ->whereHas('account', fn ($query) => $query->where('status', DealerAccount::STATUS_ACTIVE))
            ->with('account')->firstOrFail();
    }
}
