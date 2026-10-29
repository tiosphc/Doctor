<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\DealerWalletDepositRequest;
use App\Models\User;
use App\Notifications\DealerWalletDepositRequestNotification;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DealerWalletDepositRequestService
{
    public function __construct(private readonly DealerWalletService $wallets) {}

    /** @param array<string, mixed> $data */
    public function create(DealerAccount $account, User $dealer, array $data): DealerWalletDepositRequest
    {
        $path = $data['payment_proof']->store('dealer-wallet-proofs', 'local');

        try {
            $depositRequest = DB::transaction(function () use ($account, $dealer, $data, $path): DealerWalletDepositRequest {
                $wallet = $this->wallets->ensure($account);

                return DealerWalletDepositRequest::query()->create([
                    'request_code' => 'WDR'.Str::upper((string) Str::ulid()),
                    'dealer_account_id' => $account->id,
                    'dealer_wallet_id' => $wallet->id,
                    'created_by_user_id' => $dealer->id,
                    'amount' => $data['amount'],
                    'payment_proof_path' => $path,
                    'transaction_reference' => trim((string) ($data['transaction_reference'] ?? '')) ?: null,
                    'note' => trim((string) ($data['note'] ?? '')) ?: null,
                    'status' => 'pending',
                    'operation_key' => (string) Str::uuid(),
                ]);
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        User::query()->where('role', User::ROLE_ADMIN)->each(function (User $admin) use ($depositRequest): void {
            try {
                $admin->notify(new DealerWalletDepositRequestNotification($depositRequest));
            } catch (\Throwable $exception) {
                report($exception);
            }
        });

        return $depositRequest;
    }

    public function approve(DealerWalletDepositRequest $depositRequest, User $admin): DealerWalletDepositRequest
    {
        return DB::transaction(function () use ($depositRequest, $admin): DealerWalletDepositRequest {
            $locked = DealerWalletDepositRequest::query()->lockForUpdate()->findOrFail($depositRequest->id);
            if ($locked->status !== 'pending' || $locked->dealer_wallet_transaction_id !== null) {
                $this->conflict('DEALER_WALLET_DEPOSIT_REQUEST_ALREADY_REVIEWED');
            }

            $deposit = $this->wallets->recordDeposit($locked->dealerAccount, [
                'amount' => (string) $locked->amount,
                'method' => 'bank_transfer',
                'external_reference' => $locked->transaction_reference,
                'note' => 'Yêu cầu '.$locked->request_code.($locked->note ? ': '.$locked->note : ''),
                'operation_key' => $locked->operation_key,
            ], $admin);
            $transaction = $deposit->transaction()->firstOrFail();
            $locked->update([
                'dealer_wallet_transaction_id' => $transaction->id,
                'status' => 'approved',
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $locked->refresh();
        }, 3);
    }

    public function reject(DealerWalletDepositRequest $depositRequest, User $admin, string $reason): DealerWalletDepositRequest
    {
        return DB::transaction(function () use ($depositRequest, $admin, $reason): DealerWalletDepositRequest {
            $locked = DealerWalletDepositRequest::query()->lockForUpdate()->findOrFail($depositRequest->id);
            if ($locked->status !== 'pending' || $locked->dealer_wallet_transaction_id !== null) {
                $this->conflict('DEALER_WALLET_DEPOSIT_REQUEST_ALREADY_REVIEWED');
            }
            $locked->update([
                'status' => 'rejected',
                'rejection_reason' => trim($reason),
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $locked->refresh();
        }, 3);
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
