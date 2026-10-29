<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('sales-promotions:clear-data {--apply : Delete current campaign configuration} {--force : Allow the operation in production}')]
#[Description('Preview or remove Sales Promotion campaigns while preserving order and redemption snapshots')]
class ClearSalesPromotionData extends Command
{
    public function handle(): int
    {
        $this->line('Environment: '.app()->environment());
        $counts = [
            'promotions' => DB::table('sales_promotions')->count(),
            'product/category targets' => DB::table('sales_promotion_targets')->count(),
            'gift rules' => DB::table('sales_promotion_gift_rules')->count(),
            'dealer tier conditions' => DB::table('sales_promotion_dealer_tiers')->count(),
            'historical redemptions to retain' => DB::table('sales_promotion_redemptions')->whereNotNull('sales_promotion_id')->count(),
            'historical orders to retain' => DB::table('sales_orders')->whereNotNull('sales_promotion_id')->count(),
            'historical gift items to retain' => DB::table('sales_order_items')->whereNotNull('source_promotion_id')->count(),
        ];
        foreach ($counts as $label => $count) {
            $this->line("{$label}: {$count}");
        }

        if (! $this->option('apply')) {
            $this->info('Preview only. Pass --apply to clear Sales Promotion data.');

            return self::SUCCESS;
        }
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Production cleanup requires --force.');

            return self::FAILURE;
        }

        try {
            $deleted = DB::transaction(function (): array {
                $promotionIds = DB::table('sales_promotions')->lockForUpdate()->pluck('id')->all();
                if ($promotionIds === []) {
                    return ['promotions' => 0, 'product/category targets' => 0,
                        'gift rules' => 0, 'dealer tier conditions' => 0];
                }

                $deleted = [];
                foreach (['sales_promotion_targets' => 'product/category targets',
                    'sales_promotion_gift_rules' => 'gift rules',
                    'sales_promotion_dealer_tiers' => 'dealer tier conditions'] as $table => $label) {
                    $deleted[$label] = DB::table($table)->whereIn('sales_promotion_id', $promotionIds)->delete();
                }
                $deleted['promotions'] = DB::table('sales_promotions')->whereIn('id', $promotionIds)->delete();

                if ($deleted['promotions'] !== count($promotionIds)) {
                    throw new \RuntimeException('Campaign count changed during cleanup.');
                }

                return $deleted;
            });
        } catch (Throwable $exception) {
            $this->error('Cleanup rolled back: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($deleted as $label => $count) {
            $this->line("Deleted {$label}: {$count}");
        }
        $this->info('Sales Promotion data cleaned. Historical orders, items, and redemptions were retained.');

        return self::SUCCESS;
    }
}
