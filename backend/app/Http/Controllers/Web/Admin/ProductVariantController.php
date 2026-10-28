<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveBladeProductVariantRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductVariantWriteService;
use Illuminate\Http\RedirectResponse;

class ProductVariantController extends Controller
{
    public function store(SaveBladeProductVariantRequest $request, Product $product, ProductVariantWriteService $writer): RedirectResponse
    {
        if ($product->status === 'draft') {
            return $this->redirectDraft($product);
        }
        $writer->create($product, $request->validated());

        return redirect()->route('admin.web.products.edit', $product)->with('success', 'Đã thêm SKU.');
    }

    public function update(SaveBladeProductVariantRequest $request, Product $product, ProductVariant $variant, ProductVariantWriteService $writer): RedirectResponse
    {
        if ($product->status === 'draft') {
            return $this->redirectDraft($product);
        }
        if (! $writer->update($product, $variant, $request->validated())) {
            return back()->withErrors(['variant_'.$variant->id => 'Không thể đổi SKU, đơn vị hoặc theo dõi tồn kho sau khi có lịch sử kho.']);
        }

        return redirect()->route('admin.web.products.edit', $product)->with('success', 'Đã cập nhật SKU.');
    }

    private function redirectDraft(Product $product): RedirectResponse
    {
        abort_unless($product->wizard_owner_user_id === auth()->id(), 404);

        return redirect()->route('admin.web.products.drafts.edit', $product)
            ->withErrors(['product' => 'Hãy hoàn tất bản nháp trong wizard trước khi quản lý SKU.']);
    }
}
