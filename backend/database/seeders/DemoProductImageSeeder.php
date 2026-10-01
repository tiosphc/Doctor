<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoProductImageSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo image seeding is restricted to local and testing.');
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
