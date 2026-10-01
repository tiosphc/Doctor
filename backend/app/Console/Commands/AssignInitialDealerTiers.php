<?php

namespace App\Console\Commands;

use App\Models\DealerAccount;
use App\Models\DealerTierHistory;
use App\Services\DealerTierService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Exceptions\HttpResponseException;

#[Signature('dealers:assign-initial-tier {--dry-run} {--dealer=} {--limit=100}')]
#[Description('Assign the configured initial Tier to existing Dealer Accounts without a Tier')]
class AssignInitialDealerTiers extends Command
{
    public function handle(DealerTierService $tiers): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $dealerId = $this->option('dealer');
        if ($limit === false || $limit < 1 || $limit > 1000 || ($dealerId !== null && (! ctype_digit((string) $dealerId) || (int) $dealerId < 1))) {
            $this->error('Use --limit between 1 and 1000 and a positive --dealer ID.');

            return self::FAILURE;
        }
        $accounts = DealerAccount::query()->when($dealerId !== null, fn ($query) => $query->whereKey((int) $dealerId))
            ->orderBy('current_tier_id')->orderBy('id')->limit($limit)->get();
        $need = $accounts->whereNull('current_tier_id')->count();
        $assigned = 0;
        $conflicts = 0;
        $default = null;
        if ($need > 0) {
            try {
                $default = $tiers->defaultInitial();
            } catch (HttpResponseException) {
                $conflicts = $need;
            }
        }
        if (! $this->option('dry-run') && $default !== null) {
            foreach ($accounts->whereNull('current_tier_id') as $account) {
                try {
                    $tiers->assignInitial($account, source: DealerTierHistory::SOURCE_MIGRATION);
                    $assigned++;
                } catch (\Throwable $exception) {
                    $conflicts++;
                    $this->warn("Dealer #{$account->id}: {$exception->getMessage()}");
                }
            }
        }
        $this->line('Scanned: '.$accounts->count());
        $this->line('Already assigned: '.($accounts->count() - $need));
        $this->line('Need assignment: '.$need);
        $this->line('Default Tier: '.($default?->code ?? 'NOT CONFIGURED'));
        $this->line('Assigned: '.$assigned);
        $this->line('Conflicts: '.$conflicts);
        if ($this->option('dry-run')) {
            $this->info('Dry run: no Dealer Account was changed.');
        }

        return $conflicts > 0 ? self::FAILURE : self::SUCCESS;
    }
}
