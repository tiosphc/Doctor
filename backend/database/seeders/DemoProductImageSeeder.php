<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoProductImageSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'blade_qa'])) {
            throw new RuntimeException('Demo image seeding is restricted to local, testing and blade_qa.');
        }
        if (app()->environment('blade_qa') && (
            config('database.default') !== 'mysql'
            || config('database.connections.mysql.host') !== '127.0.0.1'
            || config('database.connections.mysql.database') !== 'aesthetic_clinic_blade_qa'
            || config('database.connections.mysql.username') !== 'doctor_blade_qa'
            || DB::connection()->getDatabaseName() !== 'aesthetic_clinic_blade_qa'
        )) {
            throw new RuntimeException('Blade QA image seeding requires the isolated QA database and QA user.');
        }

        $assetDirectory = database_path('seeders/assets/products');
        $manifest = json_decode(
            file_get_contents($assetDirectory.'/sources.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($manifest['products'] as $productCode => $image) {
            $product = Product::query()->where('product_code', $productCode)->first();
            if ($product === null) {
                continue;
            }

            $path = 'products/demo/'.$productCode.'.jpg';
            $existingDemoImage = $product->images()->where('path', $path)->first();
            if ($existingDemoImage === null && $product->images()->exists()) {
                continue;
            }

            if (! Storage::disk('public')->exists($path)) {
                $asset = file_get_contents($assetDirectory.'/'.$image['file']);
                if ($asset === false) {
                    throw new RuntimeException('Missing demo product image: '.$image['file']);
                }
                if (! Storage::disk('public')->put($path, $asset)) {
                    throw new RuntimeException('Could not store demo product image: '.$path);
                }
            }

            if ($existingDemoImage === null) {
                $product->images()->create([
                    'path' => $path,
                    'alt_text' => $product->name.' (ảnh minh họa)',
                    'sort_order' => 0,
                    'is_primary' => true,
                ]);
            }
        }
    }
}
