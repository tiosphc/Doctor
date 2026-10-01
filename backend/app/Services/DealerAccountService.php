<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class DealerAccountService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function update(DealerAccount $account, array $data, User $actor): DealerAccount
    {
        return DB::transaction(function () use ($account, $data, $actor): DealerAccount {
            $locked = DealerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $before = $locked->only(array_keys($data));
            $locked->update([...$data, 'updated_by' => $actor->id]);
            $changes = $this->audit->diff($before, $locked->only(array_keys($data)));
            if ($changes['old'] !== []) {
                $this->audit->log(AuditLogger::ACTION_UPDATE, AuditLogger::MODULE_DEALER_ACCOUNT, $locked, 'Dealer Account business data updated', $changes['old'], $changes['new']);
            }

            return $locked;
        }, 3);
    }

    public function transition(DealerAccount $account, string $target, User $actor): DealerAccount
    {
        return DB::transaction(function () use ($account, $target, $actor): DealerAccount {
            $locked = DealerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $allowed = [
                DealerAccount::STATUS_ACTIVE => [DealerAccount::STATUS_SUSPENDED, DealerAccount::STATUS_INACTIVE],
                DealerAccount::STATUS_SUSPENDED => [DealerAccount::STATUS_ACTIVE, DealerAccount::STATUS_INACTIVE],
                DealerAccount::STATUS_INACTIVE => [DealerAccount::STATUS_ACTIVE],
            ];
            if (! in_array($target, $allowed[$locked->status] ?? [], true)) {
                throw new HttpResponseException(response()->json(['code' => 'DEALER_STATUS_TRANSITION_INVALID', 'message' => 'DEALER_STATUS_TRANSITION_INVALID'], 409));
            }
            $old = $locked->status;
            $timestamps = [];
            $timestamps[$target === DealerAccount::STATUS_ACTIVE ? 'activated_at' : ($target === DealerAccount::STATUS_SUSPENDED ? 'suspended_at' : 'inactivated_at')] = now();
            $locked->update([...$timestamps, 'status' => $target, 'updated_by' => $actor->id]);
            $this->audit->log($target === DealerAccount::STATUS_ACTIVE ? AuditLogger::ACTION_ACTIVATE : AuditLogger::ACTION_DEACTIVATE, AuditLogger::MODULE_DEALER_ACCOUNT, $locked, 'Dealer Account status changed', ['status' => $old], ['status' => $target]);

            return $locked;
        }, 3);
    }
}
