<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProductImageRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductImageWriteService;
use Illuminate\Http\RedirectResponse;

class ProductImageController extends Controller
{
    public function store(SaveProductImageRequest $request, Product $product, ProductImageWriteService $writer): RedirectResponse
    {
        $this->assertDraftOwner($product);
        $data = $request->validated();
        unset($data['image']);
        $writer->create($product, $request->file('image'), $data);

        return redirect()->route($this->destination($product), $product)->with('success', 'Đã tải ảnh lên.');
    }

    public function update(SaveProductImageRequest $request, Product $product, ProductImage $image, ProductImageWriteService $writer): RedirectResponse
    {
        $this->assertDraftOwner($product);
        $data = $request->validated();
        unset($data['image']);
        $writer->update($product, $image, $data, $request->file('image'));

        return redirect()->route($this->destination($product), $product)->with('success', 'Đã cập nhật ảnh.');
    }

    public function destroy(Product $product, ProductImage $image, ProductImageWriteService $writer): RedirectResponse
    {
        $this->assertDraftOwner($product);
        $writer->delete($product, $image);

        return redirect()->route($this->destination($product), $product)->with('success', 'Đã xóa ảnh.');
    }

    private function assertDraftOwner(Product $product): void
    {
        if ($product->status === 'draft') {
            abort_unless($product->wizard_owner_user_id === auth()->id(), 404);
        }
    }

    private function destination(Product $product): string
    {
        return $product->status === 'draft' ? 'admin.web.products.drafts.edit' : 'admin.web.products.edit';
    }
}
