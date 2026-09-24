<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProductImageRequest;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductImageController extends Controller
{
    public function store(SaveProductImageRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();
        $path = $request->file('image')->store('products', 'public');
        unset($data['image']);
        try {
            $image = DB::transaction(function () use ($product, $data, $path): ProductImage {
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

        return response()->json(['data' => $image], 201);
    }

    public function update(SaveProductImageRequest $request, Product $product, ProductImage $image): JsonResponse
    {
        abort_unless($image->product_id === $product->id, 404);
        $data = $request->validated();
        $oldPath = $image->path;
        $newPath = $request->hasFile('image') ? $request->file('image')->store('products', 'public') : null;
        unset($data['image']);
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

        return response()->json(['data' => $image->refresh()]);
    }

    public function destroy(Product $product, ProductImage $image): JsonResponse
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

        return response()->json(status: 204);
    }
}
