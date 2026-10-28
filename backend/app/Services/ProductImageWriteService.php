<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductImageWriteService
{
    /** @param array<string, mixed> $data */
    public function create(Product $product, UploadedFile $file, array $data): ProductImage
    {
        $path = $file->store('products', 'public');
        try {
            return DB::transaction(function () use ($product, $data, $path): ProductImage {
                Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $primary = ($data['is_primary'] ?? false) || ! $product->images()->exists();
                if ($primary) {
                    $product->images()->update(['is_primary' => false]);
                }

                return $product->images()->create([...$data, 'path' => $path, 'is_primary' => $primary]);
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(Product $product, ProductImage $image, array $data, ?UploadedFile $file = null): ProductImage
    {
        abort_unless($image->product_id === $product->id, 404);
        $oldPath = $image->path;
        $newPath = $file?->store('products', 'public');
        try {
            DB::transaction(function () use ($product, $image, $data, $newPath): void {
                Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                if ($data['is_primary'] ?? false) {
                    $product->images()->update(['is_primary' => false]);
                }
                $image->update([...$data, ...($newPath ? ['path' => $newPath] : [])]);
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }
            throw $exception;
        }
        if ($newPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return $image->refresh();
    }

    public function delete(Product $product, ProductImage $image): void
    {
        abort_unless($image->product_id === $product->id, 404);
        DB::transaction(function () use ($product, $image): void {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $wasPrimary = $image->is_primary;
            $image->delete();
            if ($wasPrimary) {
                $product->images()->orderBy('sort_order')->orderBy('id')->first()?->update(['is_primary' => true]);
            }
        });
        Storage::disk('public')->delete($image->path);
    }
}
