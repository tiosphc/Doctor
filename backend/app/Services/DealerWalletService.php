<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\DealerWallet;
use App\Models\DealerWalletDeposit;
use App\Models\DealerWalletTransaction;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DealerWalletService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function ensure(DealerAccount $account): DealerWallet
    {
        try {
            return DealerWallet::query()->firstOrCreate(
                ['dealer_account_id' => $account->id, 'currency' => 'VND'],
                ['balance' => '0.00'],
            );
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }

            return DealerWallet::query()->where('dealer_account_id', $account->id)->where('currency', 'VND')->firstOrFail();
        }
    }

    /** @param array<string, mixed> $data */
    public function recordDeposit(DealerAccount $account, array $data, User $actor): DealerWalletDeposit
    {
        $amount = $this->amount((string) $data['amount']);
        $reference = trim((string) ($data['external_reference'] ?? '')) ?: null;
        $normalized = $reference === null ? null : mb_strtoupper($reference);
        $note = trim((string) ($data['note'] ?? '')) ?: null;
        $fingerprint = hash('sha256', json_encode([$account->id, $amount, $data['method'], $normalized, $note], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($account, $data, $actor, $amount, $reference, $normalized, $note, $fingerprint): DealerWalletDeposit {
            $lockedAccount = DealerAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($lockedAccount->status !== DealerAccount::STATUS_ACTIVE) {
                $this->conflict('DEALER_WALLET_DEPOSIT_ACCOUNT_INACTIVE');
            }
            $wallet = DealerWallet::query()->where('dealer_account_id', $lockedAccount->id)->where('currency', 'VND')->lockForUpdate()->first();
            $wallet ??= $this->ensure($lockedAccount);
            $existing = DealerWalletDeposit::query()->where('operation_key', $data['operation_key'])->first();
            if ($existing !== null) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    $this->conflict('DEALER_WALLET_DEPOSIT_OPERATION_CONFLICT');
                }

                return $existing;
            }
            if ($normalized !== null && DealerWalletDeposit::query()->where('method', $data['method'])->where('external_reference_normalized', $normalized)->exists()) {
                $this->conflict('WALLET_DEPOSIT_REFERENCE_ALREADY_USED');
            }
            $before = (string) $wallet->balance;
            $after = bcadd($before, $amount, 2);
            $deposit = DealerWalletDeposit::query()->create([
                'deposit_code' => 'WDP'.Str::upper((string) Str::ulid()), 'dealer_wallet_id' => $wallet->id,
                'dealer_account_id' => $lockedAccount->id, 'currency' => 'VND', 'amount' => $amount,
                'method' => $data['method'], 'external_reference' => $reference, 'external_reference_normalized' => $normalized,
                'note' => $note, 'status' => 'completed', 'recorded_by_user_id' => $actor->id,
                'operation_key' => $data['operation_key'], 'request_fingerprint' => $fingerprint, 'completed_at' => now(),
            ]);
            $wallet->transactions()->create([
                'transaction_code' => 'WTX'.Str::upper((string) Str::ulid()), 'direction' => 'credit', 'type' => 'deposit_credit',
                'amount' => $amount, 'currency' => 'VND', 'balance_before' => $before, 'balance_after' => $after,
                'dealer_wallet_deposit_id' => $deposit->id, 'actor_user_id' => $actor->id, 'operation_key' => $data['operation_key'],
            ]);
            $wallet->update(['balance' => $after]);
            $this->audit->log(AuditLogger::ACTION_CREATE, 'dealer_wallet_deposit', $deposit, 'Dealer prepaid deposit recorded', metadata: ['dealer_account_id' => $lockedAccount->id, 'deposit_code' => $deposit->deposit_code, 'amount' => $amount]);

            return $deposit->load('wallet');
        }, 3);
    }

    public function debitForOrder(SalesOrder $order, string $operationKey, int $actorId): Payment
    {
        if ($order->sales_channel !== 'dealer' || $order->dealer_account_id === null) {
            $this->conflict('DEALER_WALLET_ORDER_REQUIRED');
        }
        $amount = bcadd((string) $order->grand_total, '0', 2);
        if (bccomp($amount, '0', 2) <= 0) {
            $this->conflict('DEALER_WALLET_ORDER_AMOUNT_INVALID');
        }
        $fingerprint = hash('sha256', json_encode([$order->id, $amount, $operationKey], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($order, $operationKey, $actorId, $amount, $fingerprint): Payment {
            $lockedOrder = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $existing = DealerWalletTransaction::query()->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                $payment = $existing->payment;
                if ($payment === null || $existing->sales_order_id !== $lockedOrder->id) {
                    $this->conflict('DEALER_WALLET_OPERATION_CONFLICT');
                }

                return $payment->load('allocations');
            }
            if ($lockedOrder->order_status !== 'confirmed' || $lockedOrder->dealer_account_id === null) {
                $this->conflict('DEALER_WALLET_ORDER_INVALID_STATE');
            }
            $wallet = DealerWallet::query()->where('dealer_account_id', $lockedOrder->dealer_account_id)
                ->where('currency', $lockedOrder->currency)->lockForUpdate()->first();
            if ($wallet === null) {
                $this->conflict('DEALER_WALLET_NOT_FOUND');
            }
            $before = (string) $wallet->balance;
            if (bccomp($before, $amount, 2) < 0) {
                $this->conflict('DEALER_WALLET_INSUFFICIENT_BALANCE', ['balance' => $before, 'required' => $amount]);
            }
            $after = bcsub($before, $amount, 2);
            $payment = Payment::query()->create([
                'payment_code' => 'PAY'.Str::upper((string) Str::ulid()),
                'payment_context' => 'dealer', 'dealer_account_id' => $lockedOrder->dealer_account_id,
                'payer_user_id' => $lockedOrder->buyer_user_id, 'currency' => $lockedOrder->currency,
                'amount' => $amount, 'payment_method' => 'dealer_wallet', 'status' => 'pending',
                'recorded_by_user_id' => $actorId, 'operation_key' => $operationKey,
                'request_fingerprint' => $fingerprint, 'settled_at' => null,
            ]);
            $payment->allocations()->create(['sales_order_id' => $lockedOrder->id, 'allocated_amount' => $amount]);
            $transaction = $wallet->transactions()->create([
                'transaction_code' => 'WTX'.Str::upper((string) Str::ulid()), 'direction' => 'debit',
                'type' => 'order_debit', 'amount' => $amount, 'currency' => $wallet->currency,
                'balance_before' => $before, 'balance_after' => $after, 'sales_order_id' => $lockedOrder->id,
                'payment_id' => $payment->id, 'actor_user_id' => $actorId, 'operation_key' => $operationKey,
            ]);
            $wallet->update(['balance' => $after]);
            $payment->update(['status' => 'settled', 'settled_at' => now()]);
            $lockedOrder->update(['payment_status' => 'paid', 'payment_method' => 'dealer_wallet']);
            $this->audit->log(AuditLogger::ACTION_PAYMENT_RECORDED, AuditLogger::MODULE_PAYMENT, $payment,
                'Dealer Wallet payment settled', metadata: ['sales_order_id' => $lockedOrder->id,
                    'wallet_transaction_id' => $transaction->id, 'amount' => $amount]);
            $accountId = $lockedOrder->dealer_account_id;
            DB::afterCommit(static function () use ($accountId): void {
                try {
                    app(DealerAutoTierService::class)->upgradeIfEligible($accountId);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

            return $payment->load('allocations');
        }, 3);
    }

    public function creditRefund(Refund $refund, int $actorId): DealerWalletTransaction
    {
        return DB::transaction(function () use ($refund, $actorId): DealerWalletTransaction {
            $lockedRefund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            $existing = DealerWalletTransaction::query()->where('refund_id', $lockedRefund->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($lockedRefund->sales_order_id);
            if ($lockedRefund->refund_method !== 'dealer_wallet' || $order->sales_channel !== 'dealer' || $order->dealer_account_id === null) {
                $this->conflict('DEALER_WALLET_REFUND_REQUIRED');
            }
            $wallet = DealerWallet::query()->where('dealer_account_id', $order->dealer_account_id)
                ->where('currency', $lockedRefund->currency)->lockForUpdate()->first();
            if ($wallet === null) {
                $this->conflict('DEALER_WALLET_NOT_FOUND');
            }
            $before = (string) $wallet->balance;
            $amount = bcadd((string) $lockedRefund->amount, '0', 2);
            $after = bcadd($before, $amount, 2);
            $transaction = $wallet->transactions()->create([
                'transaction_code' => 'WTX'.Str::upper((string) Str::ulid()), 'direction' => 'credit',
                'type' => 'refund_credit', 'amount' => $amount, 'currency' => $wallet->currency,
                'balance_before' => $before, 'balance_after' => $after,
                'refund_id' => $lockedRefund->id, 'actor_user_id' => $actorId,
                'operation_key' => $lockedRefund->operation_key,
            ]);
            $wallet->update(['balance' => $after]);
            $this->audit->log(AuditLogger::ACTION_REFUND_COMPLETED, AuditLogger::MODULE_REFUND, $lockedRefund,
                'Dealer Wallet refund credited', metadata: ['wallet_transaction_id' => $transaction->id, 'amount' => $amount]);

            return $transaction;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function summary(DealerAccount $account): array
    {
        $wallet = $this->ensure($account);

        return ['currency' => $wallet->currency, 'balance' => $wallet->balance, 'available_balance' => $wallet->balance];
    }

    private function amount(string $amount): string
    {
        if (preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/', $amount) !== 1 || bccomp($amount, '0', 2) <= 0) {
            $this->conflict('DEALER_WALLET_DEPOSIT_AMOUNT_INVALID');
        }

        return bcadd($amount, '0', 2);
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
