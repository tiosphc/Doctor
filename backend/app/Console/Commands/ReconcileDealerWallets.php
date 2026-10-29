<?php

namespace App\Console\Commands;

use App\Models\DealerWallet;
use App\Models\DealerWalletTransaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('dealer-wallets:reconcile {--apply : Repair only safe cached balance drift} {--dry-run : Report anomalies without changes} {--dealer= : Limit to one Dealer Account}')]
#[Description('Audit Dealer Wallet ledger chains and cached balances')]
class ReconcileDealerWallets extends Command
{
    public function handle(): int
    {
        $query = DealerWallet::query()->orderBy('id');
        if ($this->option('dealer') !== null) {
            $query->where('dealer_account_id', (int) $this->option('dealer'));
        }
        $anomalies = 0;
        $unrepaired = 0;
        $checked = 0;
        $query->each(function (DealerWallet $wallet) use (&$anomalies, &$unrepaired, &$checked): void {
            $checked++;
            $balance = '0.00';
            $previous = '0.00';
            $validChain = true;
            foreach (DealerWalletTransaction::query()->where('dealer_wallet_id', $wallet->id)->orderBy('id')->get() as $transaction) {
                if (bccomp((string) $transaction->balance_before, $previous, 2) !== 0) {
                    $this->warn("Wallet {$wallet->id}: broken balance chain at transaction {$transaction->id}");
                    $anomalies++;
                    $unrepaired++;
                    $validChain = false;
                }
                $balance = $transaction->direction === 'credit'
                    ? bcadd($balance, (string) $transaction->amount, 2)
                    : bcsub($balance, (string) $transaction->amount, 2);
                if (bccomp($balance, (string) $transaction->balance_after, 2) !== 0) {
                    $this->warn("Wallet {$wallet->id}: transaction {$transaction->id} amount mismatch");
                    $anomalies++;
                    $unrepaired++;
                    $validChain = false;
                }
                $previous = (string) $transaction->balance_after;
            }
            if (bccomp((string) $wallet->balance, $balance, 2) !== 0) {
                $this->warn("Wallet {$wallet->id}: cached balance {$wallet->balance} != ledger {$balance}");
                $anomalies++;
                if ($this->option('apply') && $validChain) {
                    DB::table('dealer_wallets')->where('id', $wallet->id)->update(['balance' => $balance, 'updated_at' => now()]);
                    $this->info("Wallet {$wallet->id}: cached balance repaired");
                } else {
                    $unrepaired++;
                }
            }
        });
        $this->line("Checked {$checked} wallet(s); {$anomalies} anomaly/anomalies.");

        return $unrepaired === 0 ? self::SUCCESS : self::FAILURE;
    }
}
