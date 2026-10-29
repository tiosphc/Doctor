<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\DealerShippingAddress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DealerShippingAddressService
{
    /** @return Collection<int, DealerShippingAddress> */
    public function forAccount(DealerAccount $account): Collection
    {
        return DealerShippingAddress::query()->with(['province', 'ward'])
            ->where('dealer_account_id', $account->id)->orderByDesc('is_default')->latest('id')->get();
    }

    public function findForAccount(DealerAccount $account, int $addressId): DealerShippingAddress
    {
        return DealerShippingAddress::query()->where('dealer_account_id', $account->id)
            ->with(['province', 'ward'])->findOrFail($addressId);
    }

    /** @param array<string, mixed> $data */
    public function create(DealerAccount $account, array $data): DealerShippingAddress
    {
        return DB::transaction(function () use ($account, $data): DealerShippingAddress {
            DealerAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $isDefault = (bool) ($data['is_default'] ?? false)
                || ! DealerShippingAddress::query()->where('dealer_account_id', $account->id)->exists();
            if ($isDefault) {
                DealerShippingAddress::query()->where('dealer_account_id', $account->id)->update(['is_default' => false]);
            }

            return DealerShippingAddress::query()->create([...$data,
                'dealer_account_id' => $account->id, 'is_default' => $isDefault])->load(['province', 'ward']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(DealerAccount $account, int $addressId, array $data): DealerShippingAddress
    {
        return DB::transaction(function () use ($account, $addressId, $data): DealerShippingAddress {
            DealerAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $address = DealerShippingAddress::query()->where('dealer_account_id', $account->id)
                ->lockForUpdate()->findOrFail($addressId);
            if ($data['is_default'] ?? false) {
                DealerShippingAddress::query()->where('dealer_account_id', $account->id)->update(['is_default' => false]);
            }
            $address->update($data);

            return $address->refresh()->load(['province', 'ward']);
        });
    }

    public function delete(DealerAccount $account, int $addressId): void
    {
        DB::transaction(function () use ($account, $addressId): void {
            DealerAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            DealerShippingAddress::query()->where('dealer_account_id', $account->id)
                ->lockForUpdate()->findOrFail($addressId)->delete();
        });
    }
}
