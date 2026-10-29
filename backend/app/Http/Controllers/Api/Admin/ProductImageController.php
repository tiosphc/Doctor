<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProductImageRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductImageWriteService;
use Illuminate\Http\JsonResponse;

class ProductImageController extends Controller
{
    public function store(SaveProductImageRequest $request, Product $product, ProductImageWriteService $writer): JsonResponse
    {
        $data = $request->validated();
        unset($data['image']);
        $image = $writer->create($product, $request->file('image'), $data);

        return response()->json(['data' => $image], 201);
    }

    public function update(SaveProductImageRequest $request, Product $product, ProductImage $image, ProductImageWriteService $writer): JsonResponse
    {
        $data = $request->validated();
        unset($data['image']);
        $writer->update($product, $image, $data, $request->file('image'));

        return response()->json(['data' => $image->refresh()]);
    }

    public function destroy(Product $product, ProductImage $image, ProductImageWriteService $writer): JsonResponse
    {
        $writer->delete($product, $image);

        return response()->json(status: 204);
    }
}
