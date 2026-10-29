<?php

namespace Database\Seeders;

use App\Models\ProductVariant;
use App\Models\SalesOrderItem;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductUnitSeeder extends Seeder
{
    private const UNITS = [
        'CAI' => ['Cái', 'cái'],
        'HOP' => ['Hộp', 'hộp'],
        'CHAI' => ['Chai', 'chai'],
        'LO' => ['Lọ', 'lọ'],
        'TUYP' => ['Tuýp', 'tuýp'],
        'GOI' => ['Gói', 'gói'],
        'MIENG' => ['Miếng', 'miếng'],
        'BOM' => ['Bơm', 'bơm'],
        'BO' => ['Bộ', 'bộ'],
    ];

    private const DEMO_PRODUCT_UNITS = [
        'DEMO-PRD-01' => 'CHAI',
        'DEMO-PRD-02' => 'TUYP',
        'DEMO-PRD-03' => 'CHAI',
        'DEMO-PRD-04' => 'BO',
        'DEMO-PRD-05' => 'MIENG',
        'DEMO-PRD-06' => 'CHAI',
        'DEMO-PRD-07' => 'TUYP',
        'DEMO-PRD-08' => 'CHAI',
        'DEMO-PRD-09' => 'TUYP',
        'DEMO-PRD-10' => 'LO',
        'DEMO-PRD-11' => 'HOP',
        'DEMO-PRD-12' => 'CHAI',
        'DEMO-PRD-13' => 'BO',
        'DEMO-GIFT-01' => 'CHAI',
        'DEMO-GIFT-02' => 'MIENG',
        'DEMO-GIFT-03' => 'BO',
    ];

    public static function unitCodeForDemoProduct(string $productCode): string
    {
        return self::DEMO_PRODUCT_UNITS[$productCode]
            ?? throw new RuntimeException('Demo Product has no assigned unit: '.$productCode);
    }

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::UNITS as $code => [$name, $symbol]) {
                Unit::query()->firstOrCreate(['code' => $code], [
                    'name' => $name,
                    'symbol' => $symbol,
                    'decimal_precision' => 0,
                    'status' => 'active',
                ]);
            }

            $legacyBox = Unit::query()->where('code', 'DEMO-BOX')->first();
            $unitIds = Unit::query()->whereIn('code', array_values(self::DEMO_PRODUCT_UNITS))
                ->pluck('id', 'code');
            foreach (self::DEMO_PRODUCT_UNITS as $productCode => $unitCode) {
                if ($legacyBox !== null) {
                    ProductVariant::query()->whereHas('product', fn ($query) => $query->where('product_code', $productCode))
                        ->where('unit_id', $legacyBox->id)
                        ->update(['unit_id' => $unitIds[$unitCode]]);
                }

                /** Correct only legacy demo labels; order quantities and financial snapshots stay intact. */
                SalesOrderItem::query()->where('product_code_snapshot', $productCode)
                    ->where('unit_code_snapshot', 'DEMO-BOX')
                    ->whereHas('productVariant.product', fn ($query) => $query->where('product_code', $productCode))
                    ->update(['unit_code_snapshot' => $unitCode, 'unit_name_snapshot' => self::UNITS[$unitCode][0]]);
            }
        });
    }
}
