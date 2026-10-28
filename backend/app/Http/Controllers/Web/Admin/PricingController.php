<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveDealerCatalogPriceRequest;
use App\Http\Requests\Admin\SaveRetailCatalogPriceRequest;
use App\Models\DealerTier;
use App\Models\ProductVariant;
use App\Services\ProductPricingService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PricingController extends Controller
{
    public function retailIndex(Request $request, ProductPricingService $pricing): View
    {
        return $this->index($request, $pricing, 'retail');
    }

    public function dealerIndex(Request $request, ProductPricingService $pricing): View
    {
        return $this->index($request, $pricing, 'dealer');
    }

    public function retailUpdate(SaveRetailCatalogPriceRequest $request, ProductVariant $variant, ProductPricingService $pricing): RedirectResponse
    {
        try {
            $pricing->updateRetail($variant, (string) $request->validated('unit_price'), $request->user()->id);
        } catch (HttpResponseException $exception) {
            return back()->withErrors(['pricing' => $this->pricingError($exception)]);
        }

        return back()->with('success', 'Đã cập nhật giá Retail cho SKU '.$variant->sku.'.');
    }

    public function dealerUpdate(SaveDealerCatalogPriceRequest $request, ProductVariant $variant, DealerTier $tier, ProductPricingService $pricing): RedirectResponse
    {
        if ($tier->status !== 'active') {
            return back()->withErrors(['pricing' => 'Tier đã ngừng hoạt động.']);
        }
        $data = $request->validated();
        try {
            $pricing->updateDealer($variant, $tier, (string) $data['unit_price'], (int) $data['minimum_quantity'], $request->user()->id);
        } catch (HttpResponseException $exception) {
            return back()->withErrors(['pricing' => $this->pricingError($exception)]);
        }

        return back()->with('success', 'Đã cập nhật giá đại lý cho SKU '.$variant->sku.' / '.$tier->name.'.');
    }

    public function dealerDelete(Request $request, ProductVariant $variant, DealerTier $tier, ProductPricingService $pricing): RedirectResponse
    {
        try {
            $pricing->updateDealer($variant, $tier, null, 1, $request->user()->id);
        } catch (HttpResponseException $exception) {
            return back()->withErrors(['pricing' => $this->pricingError($exception)]);
        }

        return back()->with('success', 'Đã xóa giá đại lý hiện tại của SKU '.$variant->sku.' / '.$tier->name.'.');
    }

    private function index(Request $request, ProductPricingService $pricing, string $context): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'price_status' => ['nullable', Rule::in(['priced', 'unpriced'])],
            'tier_id' => $context === 'dealer' ? ['nullable', 'integer', Rule::exists('dealer_tiers', 'id')->where('status', 'active')] : ['prohibited'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $prices = $pricing->catalog($context, $filters)->withQueryString();

        return view('admin.prices.index', [
            'context' => $context,
            'prices' => $prices,
            'groups' => $prices->getCollection()->groupBy('product_id'),
            'filters' => $filters,
            'tiers' => $context === 'dealer' ? DealerTier::query()->where('status', 'active')->orderBy('sort_order')->orderBy('id')->get(['id', 'code', 'name']) : collect(),
        ]);
    }

    private function pricingError(HttpResponseException $exception): string
    {
        return match ($exception->getResponse()->getData(true)['code'] ?? null) {
            'WIZARD_DRAFT' => 'Hãy hoàn tất bản nháp trước khi quản lý giá.',
            'PRICE_BOOK_CONFLICT' => 'Bảng giá hiện tại không hợp lệ. Vui lòng kiểm tra cấu hình bảng giá.',
            default => 'Không thể cập nhật giá. Vui lòng kiểm tra lại dữ liệu.',
        };
    }
}
