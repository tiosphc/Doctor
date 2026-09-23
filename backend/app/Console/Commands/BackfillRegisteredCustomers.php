<?php

namespace App\Console\Commands;

use App\Services\RegisteredCustomerBackfillReport;
use App\Services\RegisteredCustomerBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('customers:backfill-registered
    {--dry-run : Report planned changes without mutating Customer business data}
    {--reconcile-only : Run only the read-only reconciliation report}
    {--chunk=100 : Eligible users fetched per chunk}
    {--limit= : Maximum eligible users to process for a canary run}
    {--batch=registered-v1 : Non-PII operational batch key}')]
#[Description('Backfill canonical Customers for registered users with restartable mappings')]
class BackfillRegisteredCustomers extends Command
{
    public function handle(RegisteredCustomerBackfillService $backfillService): int
    {
        $chunkSize = (int) $this->option('chunk');
        $limitOption = $this->option('limit');
        $limit = $limitOption === null ? null : (int) $limitOption;
        $batchKey = trim((string) $this->option('batch'));

        if ($chunkSize < 1 || $chunkSize > 5000) {
            $this->error('The --chunk value must be between 1 and 5000.');

            return self::INVALID;
        }

        if ($limit !== null && $limit < 1) {
            $this->error('The --limit value must be a positive integer.');

            return self::INVALID;
        }

        if ($batchKey === '' || preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $batchKey) !== 1) {
            $this->error('The --batch value must be a 1-100 character non-PII operational key.');

            return self::INVALID;
        }

        if ($this->option('reconcile-only')) {
            $report = new RegisteredCustomerBackfillReport;
            $report->reconciliation = $backfillService->reconcile();
            $this->renderReconciliation($report);

            return $report->hasUnexplainedDifferences() ? self::FAILURE : self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->components->info($dryRun
            ? 'Dry run: no Customer business data will be mutated.'
            : 'Running restartable registered-Customer backfill.');
        $report = $backfillService->run($dryRun, $chunkSize, $limit, $batchKey);

        $this->table(['Metric', 'Count'], collect($report->metrics())
            ->map(fn (int $count, string $metric): array => [$metric, $count])
            ->values()
            ->all());
        $this->renderReconciliation($report);

        if (! $dryRun && $limit === null && $report->hasUnexplainedDifferences()) {
            $this->components->error('Backfill finished with unexplained reconciliation differences. Review conflicts before continuing.');

            return self::FAILURE;
        }

        $this->components->info($dryRun
            ? 'Dry-run report completed.'
            : 'Backfill completed. Re-running is safe and reuses persisted source mappings.');

        return self::SUCCESS;
    }

    private function renderReconciliation(RegisteredCustomerBackfillReport $report): void
    {
        $rows = collect($report->reconciliation)
            ->map(fn (int $count, string $metric): array => [$metric, $count])
            ->values()
            ->all();

        $this->newLine();
        $this->components->info('Reconciliation (expected / actual / difference and integrity checks)');
        $this->table(['Metric', 'Count'], $rows);
    }
}
