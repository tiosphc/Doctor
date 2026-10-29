<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\DealerWallet;
use App\Models\DealerWalletDeposit;
use App\Models\DealerWalletTopUpRequest;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DealerWalletTopUpService
{
    public function __construct(
        private readonly WalletTopUpGateway $gateway,
        private readonly DealerWalletService $wallets,
        private readonly AuditLogger $audit,
    ) {}

    public function create(DealerAccount $account, User $actor, int $amount, string $operationKey): DealerWalletTopUpRequest
    {
        if (! $this->gateway->isConfigured()) {
            abort(503, 'PAYOS_NOT_CONFIGURED');
        }

        $fingerprint = hash('sha256', json_encode([$account->id, $actor->id, $amount], JSON_THROW_ON_ERROR));
        $topUp = DB::transaction(function () use ($account, $actor, $amount, $operationKey, $fingerprint): DealerWalletTopUpRequest {
            $lockedAccount = DealerAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($lockedAccount->status !== DealerAccount::STATUS_ACTIVE) {
                $this->conflict('DEALER_TOP_UP_ACCOUNT_INACTIVE');
            }
            $existing = DealerWalletTopUpRequest::query()->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    $this->conflict('DEALER_TOP_UP_OPERATION_CONFLICT');
                }

                return $existing;
            }

            $topUp = DealerWalletTopUpRequest::query()->create([
                'dealer_account_id' => $account->id,
                'created_by_user_id' => $actor->id,
                'currency' => 'VND',
                'amount' => number_format($amount, 2, '.', ''),
                'provider' => 'payos',
                'status' => DealerWalletTopUpRequest::STATUS_INITIATING,
                'expires_at' => now()->addMinutes(max(5, min(1440, (int) config('services.payos.top_up_ttl_minutes')))),
                'operation_key' => $operationKey,
                'request_fingerprint' => $fingerprint,
            ]);
            $topUp->update(['provider_order_code' => 100000000000 + $topUp->id]);

            return $topUp;
        }, 3);

        if ($topUp->status !== DealerWalletTopUpRequest::STATUS_INITIATING) {
            return $topUp;
        }

        $frontendUrl = rtrim((string) config('services.payos.frontend_url'), '/');
        $returnUrl = $frontendUrl.'/dealer/top-up-result/'.$account->id.'/'.$topUp->id;
        try {
            $link = $this->gateway->create((int) $topUp->provider_order_code, $amount, $returnUrl, $topUp->expires_at?->timestamp ?? now()->addMinutes(30)->timestamp);
        } catch (RuntimeException $exception) {
            $link = $this->gateway->findExisting((int) $topUp->provider_order_code, $amount);
            if ($link === null) {
                abort(502, $exception->getMessage());
            }
        }

        return DB::transaction(function () use ($account, $topUp, $link): DealerWalletTopUpRequest {
            DealerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $locked = DealerWalletTopUpRequest::query()->lockForUpdate()->findOrFail($topUp->id);
            if ($locked->provider_payment_link_id !== null && $locked->provider_payment_link_id !== $link['paymentLinkId']) {
                $this->conflict('DEALER_TOP_UP_PROVIDER_LINK_CONFLICT');
            }
            $locked->update([
                'provider_payment_link_id' => $link['paymentLinkId'],
                'checkout_url' => $link['checkoutUrl'],
                'status' => $locked->status === DealerWalletTopUpRequest::STATUS_INITIATING ? DealerWalletTopUpRequest::STATUS_PENDING : $locked->status,
            ]);

            return $locked;
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    public function handleWebhook(array $payload): void
    {
        $data = $this->gateway->verifiedWebhookData($payload);
        if ($data === null) {
            abort(401, 'PAYOS_SIGNATURE_INVALID');
        }
        if (($payload['success'] ?? false) !== true || ($payload['code'] ?? null) !== '00' || ($data['code'] ?? null) !== '00') {
            return;
        }

        $orderCode = filter_var($data['orderCode'] ?? null, FILTER_VALIDATE_INT);
        $amount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_INT);
        $reference = $data['reference'] ?? null;
        $paymentLinkId = $data['paymentLinkId'] ?? null;
        if ($orderCode === false || $amount === false || $amount <= 0 || ($data['currency'] ?? null) !== 'VND'
            || ! is_string($reference) || trim($reference) === ''
            || ! is_string($paymentLinkId) || $paymentLinkId === ''
            || (isset($data['status']) && $data['status'] !== 'PAID')) {
            $this->conflict('DEALER_TOP_UP_WEBHOOK_INVALID');
        }
        $topUp = DealerWalletTopUpRequest::query()->where('provider_order_code', $orderCode)->first();
        if ($topUp === null) {
            return;
        }

        $this->complete($topUp, $amount, $paymentLinkId, $reference, is_string($data['transactionDateTime'] ?? null) ? $data['transactionDateTime'] : null);
    }

    public function refresh(DealerWalletTopUpRequest $topUp): DealerWalletTopUpRequest
    {
        if ($topUp->status === DealerWalletTopUpRequest::STATUS_PAID) {
            return $topUp->refresh();
        }
        $result = $this->gateway->fetch((int) $topUp->provider_order_code, (int) $topUp->amount);
        if ($result->orderCode !== (int) $topUp->provider_order_code || $result->amount !== (int) $topUp->amount) {
            $this->conflict('DEALER_TOP_UP_PROVIDER_MISMATCH');
        }
        if ($result->status === 'paid') {
            if ($result->reference === null) {
                $this->conflict('DEALER_TOP_UP_PROVIDER_REFERENCE_MISSING');
            }
            $this->complete($topUp, $result->amount, $result->paymentLinkId, $result->reference, $result->paidAt);

            return $topUp->refresh();
        }

        return DB::transaction(function () use ($topUp, $result): DealerWalletTopUpRequest {
            DealerAccount::query()->lockForUpdate()->findOrFail($topUp->dealer_account_id);
            $locked = DealerWalletTopUpRequest::query()->lockForUpdate()->findOrFail($topUp->id);
            if ($locked->provider_payment_link_id !== null && $locked->provider_payment_link_id !== $result->paymentLinkId) {
                $this->conflict('DEALER_TOP_UP_PROVIDER_LINK_CONFLICT');
            }
            if ($locked->status === DealerWalletTopUpRequest::STATUS_PAID) {
                return $locked;
            }
            $updates = ['provider_checked_at' => now(), 'provider_payment_link_id' => $result->paymentLinkId];
            if (in_array($locked->status, [DealerWalletTopUpRequest::STATUS_INITIATING, DealerWalletTopUpRequest::STATUS_PENDING], true)) {
                $updates['status'] = $result->status;
                if ($result->status === DealerWalletTopUpRequest::STATUS_EXPIRED) {
                    $updates['expired_at'] = now();
                } elseif ($result->status === DealerWalletTopUpRequest::STATUS_FAILED) {
                    $updates['failed_at'] = now();
                } elseif ($result->status === DealerWalletTopUpRequest::STATUS_CANCELLED) {
                    $updates['cancelled_at'] = now();
                }
            }
            $locked->update($updates);

            return $locked;
        }, 3);
    }

    private function complete(DealerWalletTopUpRequest $topUp, int $amount, string $paymentLinkId, string $reference, ?string $providerPaidAt): void
    {
        DB::transaction(function () use ($topUp, $amount, $reference, $paymentLinkId, $providerPaidAt): void {
            $account = DealerAccount::query()->lockForUpdate()->findOrFail($topUp->dealer_account_id);
            $locked = DealerWalletTopUpRequest::query()->lockForUpdate()->findOrFail($topUp->id);
            if ((int) $locked->amount !== $amount || ($locked->provider_payment_link_id !== null && $locked->provider_payment_link_id !== $paymentLinkId)) {
                $this->conflict('DEALER_TOP_UP_WEBHOOK_MISMATCH');
            }
            if ($locked->status === 'paid') {
                if ($locked->provider_reference !== trim($reference)) {
                    $this->conflict('DEALER_TOP_UP_WEBHOOK_MISMATCH');
                }

                return;
            }
            if (! in_array($locked->status, [DealerWalletTopUpRequest::STATUS_INITIATING, DealerWalletTopUpRequest::STATUS_PENDING,
                DealerWalletTopUpRequest::STATUS_EXPIRED, DealerWalletTopUpRequest::STATUS_FAILED, DealerWalletTopUpRequest::STATUS_CANCELLED], true)) {
                $this->conflict('DEALER_TOP_UP_INVALID_STATE');
            }
            $wallet = DealerWallet::query()->where('dealer_account_id', $account->id)->where('currency', 'VND')->lockForUpdate()->first();
            $wallet ??= $this->wallets->ensure($account);
            $before = (string) $wallet->balance;
            $after = bcadd($before, (string) $locked->amount, 2);
            $normalized = mb_strtoupper(trim($reference));
            $creditKey = (string) Str::uuid();
            $deposit = DealerWalletDeposit::query()->create([
                'deposit_code' => 'WDP'.Str::upper((string) Str::ulid()),
                'dealer_wallet_id' => $wallet->id,
                'dealer_account_id' => $account->id,
                'dealer_wallet_top_up_request_id' => $locked->id,
                'currency' => 'VND',
                'amount' => $locked->amount,
                'method' => 'payos',
                'external_reference' => trim($reference),
                'external_reference_normalized' => $normalized,
                'status' => 'completed',
                'recorded_by_user_id' => null,
                'operation_key' => $creditKey,
                'request_fingerprint' => $locked->request_fingerprint,
                'completed_at' => now(),
            ]);
            $wallet->transactions()->create([
                'transaction_code' => 'WTX'.Str::upper((string) Str::ulid()),
                'direction' => 'credit',
                'type' => 'deposit_credit',
                'amount' => $locked->amount,
                'currency' => 'VND',
                'balance_before' => $before,
                'balance_after' => $after,
                'dealer_wallet_deposit_id' => $deposit->id,
                'actor_user_id' => null,
                'operation_key' => $creditKey,
            ]);
            $wallet->update(['balance' => $after]);
            $locked->update([
                'provider_payment_link_id' => $paymentLinkId,
                'provider_reference' => trim($reference),
                'status' => DealerWalletTopUpRequest::STATUS_PAID,
                'paid_at' => $this->paidAt($providerPaidAt),
                'completed_at' => now(),
            ]);
            $this->audit->log(AuditLogger::ACTION_CREATE, 'dealer_wallet_deposit', $deposit, 'PayOS dealer top-up completed', metadata: ['dealer_account_id' => $account->id, 'deposit_code' => $deposit->deposit_code, 'amount' => (string) $locked->amount]);
        }, 3);
    }

    private function paidAt(?string $value): Carbon
    {
        if ($value === null) {
            return now();
        }
        try {
            return Carbon::parse($value, 'Asia/Ho_Chi_Minh');
        } catch (\Throwable) {
            return now();
        }
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
