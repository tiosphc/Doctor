<?php

namespace App\Console\Commands;

use App\Models\DealerAccount;
use App\Services\DealerWalletService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dealer-wallets:ensure')]
#[Description('Command description')]
class EnsureDealerWallets extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DealerWalletService $wallets): int
    {
        $count = 0;
        DealerAccount::query()->orderBy('id')->each(function (DealerAccount $account) use ($wallets, &$count): void {
            $wallets->ensure($account);
            $count++;
        });
        $this->info("Ensured {$count} dealer wallets.");

        return self::SUCCESS;
    }
}
