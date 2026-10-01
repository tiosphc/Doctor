<?php

namespace App\Console\Commands;

use App\Models\DealerWalletDeposit;
use App\Models\DealerWalletTopUpRequest;
use App\Models\DealerWalletTransaction;
use App\Services\DealerWalletTopUpService;
use App\Services\WalletTopUpGateway;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Exceptions\HttpResponseException;
use RuntimeException;

#[Signature('dealer-wallet-topups:reconcile {--dry-run : Audit only (default)} {--apply : Apply only trusted PayOS status through the normal completion service} {--dealer= : Limit to one Dealer Account}')]
#[Description('Audit online top-up, Deposit, Wallet Credit and trusted PayOS status')]
class ReconcileDealerWalletTopUps extends Command
{
    public function handle(WalletTopUpGateway $gateway, DealerWalletTopUpService $topUps): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose --apply or --dry-run, not both.');

            return self::FAILURE;
        }

        $checked = 0;
        $anomalies = 0;
        $query = DealerWalletTopUpRequest::query()->orderBy('id');
        if ($this->option('dealer') !== null) {
            $query->where('dealer_account_id', (int) $this->option('dealer'));
        }
        $query->chunkById(100, function ($batch) use ($gateway, $topUps, &$checked, &$anomalies): void {
            foreach ($batch as $topUp) {
                $checked++;
                if ($topUp->status !== DealerWalletTopUpRequest::STATUS_PAID) {
                    if (! $gateway->isConfigured()) {
                        $this->warn("Top-up {$topUp->top_up_code}: PayOS is not configured; provider status unknown");
                        $anomalies++;
                    } else {
                        try {
                            $provider = $gateway->fetch((int) $topUp->provider_order_code, (int) $topUp->amount);
                            if ($topUp->provider_payment_link_id !== null && $provider->paymentLinkId !== $topUp->provider_payment_link_id) {
                                $this->warn("Top-up {$topUp->top_up_code}: provider link mismatch");
                                $anomalies++;
                            } elseif ($provider->status !== $topUp->status && ! in_array($topUp->status, ['expired', 'failed', 'cancelled'], true)) {
                                $this->warn("Top-up {$topUp->top_up_code}: local {$topUp->status}, trusted PayOS {$provider->status}");
                                if ($this->option('apply')) {
                                    $topUp = $topUps->refresh($topUp);
                                } else {
                                    $anomalies++;
                                }
                            } elseif ($provider->status === 'paid' && $topUp->status !== 'paid') {
                                $this->warn("Top-up {$topUp->top_up_code}: paid after local terminal state");
                                if ($this->option('apply')) {
                                    $topUp = $topUps->refresh($topUp);
                                } else {
                                    $anomalies++;
                                }
                            }
                        } catch (RuntimeException|HttpResponseException $exception) {
                            $this->warn("Top-up {$topUp->top_up_code}: {$exception->getMessage()}");
                            $anomalies++;
                        }
                    }
                }
                if ($topUp->status === DealerWalletTopUpRequest::STATUS_PAID) {
                    $anomalies += $this->auditCompleted($topUp);
                } elseif (DealerWalletDeposit::query()->where('dealer_wallet_top_up_request_id', $topUp->id)->exists()) {
                    $this->warn("Top-up {$topUp->top_up_code}: non-paid request has a Deposit");
                    $anomalies++;
                }
            }
        });

        $orphanQuery = DealerWalletDeposit::query()->where('method', 'payos')->whereNull('dealer_wallet_top_up_request_id');
        if ($this->option('dealer') !== null) {
            $orphanQuery->where('dealer_account_id', (int) $this->option('dealer'));
        }
        $orphans = $orphanQuery->count();
        if ($orphans > 0) {
            $this->warn("{$orphans} PayOS Deposit(s) lack a Top-Up link");
            $anomalies += $orphans;
        }
        $this->line("Checked {$checked} top-up(s); {$anomalies} anomaly/anomalies.");

        return $anomalies === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function auditCompleted(DealerWalletTopUpRequest $topUp): int
    {
        $code = $topUp->top_up_code;
        $deposit = DealerWalletDeposit::query()->where('dealer_wallet_top_up_request_id', $topUp->id)->first();
        if ($deposit === null) {
            $this->warn("Top-up {$code}: completed without Deposit");

            return 1;
        }
        $credits = DealerWalletTransaction::query()->where('dealer_wallet_deposit_id', $deposit->id)->get();
        $credit = $credits->first();
        $wallet = $deposit->wallet;
        $valid = $credits->count() === 1 && $credit !== null && $wallet !== null
            && $deposit->method === 'payos' && $deposit->status === 'completed'
            && $deposit->dealer_account_id === $topUp->dealer_account_id
            && $wallet->dealer_account_id === $topUp->dealer_account_id
            && $deposit->dealer_wallet_id === $wallet->id
            && $deposit->currency === $topUp->currency && $credit->currency === $topUp->currency
            && bccomp((string) $deposit->amount, (string) $topUp->amount, 2) === 0
            && bccomp((string) $credit->amount, (string) $topUp->amount, 2) === 0
            && $credit->dealer_wallet_id === $wallet->id
            && $credit->direction === 'credit' && $credit->type === 'deposit_credit'
            && $topUp->provider_payment_link_id !== null && $topUp->provider_reference !== null
            && $deposit->external_reference_normalized === mb_strtoupper(trim($topUp->provider_reference))
            && $topUp->paid_at !== null && $topUp->completed_at !== null;
        if (! $valid) {
            $this->warn("Top-up {$code}: Deposit/Wallet Credit linkage or amount/currency mismatch");

            return 1;
        }
        if (DealerWalletTopUpRequest::query()->where('provider_reference', $topUp->provider_reference)->where('id', '!=', $topUp->id)->exists()) {
            $this->warn("Top-up {$code}: duplicate provider transaction reference");

            return 1;
        }

        return 0;
    }
}
