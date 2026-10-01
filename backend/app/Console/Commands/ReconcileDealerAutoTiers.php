<?php

namespace App\Console\Commands;

use App\Models\DealerAccount;
use App\Services\DealerAutoTierService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Exceptions\HttpResponseException;

#[Signature('dealers:reconcile-auto-tiers {--dry-run} {--apply} {--dealer=} {--limit=0}')]
#[Description('Preview or apply automatic Dealer Tier changes from net settled revenue')]
class ReconcileDealerAutoTiers extends Command
{
    public function handle(DealerAutoTierService $service): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose either --apply or --dry-run. Dry run is the default.');

            return self::FAILURE;
        }
        $at = CarbonImmutable::now('Asia/Ho_Chi_Minh');
        if ($this->option('apply') && $at->day !== $at->daysInMonth) {
            $this->info('Automatic Dealer Tier evaluation runs only on the last day of the month.');

            return self::SUCCESS;
        }
        if ($this->option('apply') && ! $service->policy()['enabled']) {
            $this->info('Automatic Dealer Tier evaluation is disabled.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 0 || $limit > 1000000) {
            $this->error('--limit must be between 0 and 1000000 (0 means all accounts).');

            return self::FAILURE;
        }
        $query = DealerAccount::query()->orderBy('id');
        if ($this->option('dealer')) {
            $query->where('id', (int) $this->option('dealer'));
        }
        $checked = 0;
        $changed = 0;
        $mismatched = 0;
        $wouldUpgrade = 0;
        $wouldDowngrade = 0;
        $historyAnomalies = 0;
        try {
            $query->chunkById(100, function ($accounts) use ($service, $limit, &$checked, &$changed, &$mismatched,
                &$wouldUpgrade, &$wouldDowngrade, &$historyAnomalies): bool {
                foreach ($accounts as $account) {
                    if ($limit > 0 && $checked >= $limit) {
                        return false;
                    }
                    $result = $service->evaluate($account->id, (bool) $this->option('apply'));
                    $checked++;
                    $changed += (int) $result['changed'];
                    $mismatched += (int) $result['would_change'];
                    $wouldUpgrade += (int) ($result['direction'] === 'upgrade');
                    $wouldDowngrade += (int) ($result['direction'] === 'downgrade');
                    $historyAnomalies += (int) in_array($result['history_status'], ['missing', 'mismatch'], true);
                    $this->line(json_encode($result, JSON_THROW_ON_ERROR));
                }

                return true;
            });
        } catch (HttpResponseException $exception) {
            $this->error((string) $exception->getResponse()->getContent());

            return self::FAILURE;
        }
        $this->info(json_encode(['mode' => $this->option('apply') ? 'apply' : 'dry-run',
            'checked' => $checked, 'mismatched' => $mismatched, 'would_upgrade' => $wouldUpgrade,
            'would_downgrade' => $wouldDowngrade, 'history_anomalies' => $historyAnomalies,
            'changed' => $changed], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
