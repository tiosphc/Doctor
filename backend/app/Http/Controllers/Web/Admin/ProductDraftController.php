<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BladeCompleteProductWizardRequest;
use App\Http\Requests\Admin\BladeSaveProductWizardDraftRequest;
use App\Models\Brand;
use App\Models\DealerTier;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\ProductWizardService;
use App\Support\BladeProductWizardPayload;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductDraftController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $drafts = Product::query()->where('status', 'draft')
            ->where('wizard_owner_user_id', $request->user()->id)
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($products) => $products
                ->where('name', 'like', '%'.$search.'%')->orWhere('product_code', 'like', '%'.$search.'%')))
            ->withCount(['images', 'variants'])->latest('updated_at')->latest('id')
            ->paginate(20)->withQueryString();

        return view('admin.products.drafts.index', compact('drafts', 'filters'));
    }

    public function edit(Request $request, Product $product): View
    {
        $this->assertOwnedDraft($product, $request);
        $product->load(['category:id,name,code', 'brand:id,name,code',
            'images' => fn ($images) => $images->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id'),
            'variants:id,product_id,sku']);
        $oldData = old('data');
        $data = is_array($oldData) ? array_replace(BladeProductWizardPayload::forForm($product), $oldData)
            : BladeProductWizardPayload::forForm($product);
        $selectedTierIds = collect(is_array($data['dealer_rules'] ?? null) ? $data['dealer_rules'] : [])
            ->pluck('tier_id')->filter()->all();

        return view('admin.products.drafts.edit', [
            'product' => $product,
            'data' => $data,
            'draftIssue' => $this->draftIssue($product),
            'categories' => ProductCategory::query()->where(fn ($query) => $query->where('status', 'active')
                ->when($data['product_category_id'] ?? null, fn ($query, $id) => $query->orWhereKey($id)))
                ->orderBy('name')->get(['id', 'name', 'status']),
            'brands' => Brand::query()->where(fn ($query) => $query->where('status', 'active')
                ->when($data['brand_id'] ?? null, fn ($query, $id) => $query->orWhereKey($id)))
                ->orderBy('name')->get(['id', 'name', 'status']),
            'units' => Unit::query()->where(fn ($query) => $query->where('status', 'active')
                ->when($data['unit_id'] ?? null, fn ($query, $id) => $query->orWhereKey($id)))
                ->orderBy('name')->get(['id', 'name', 'symbol', 'status']),
            'warehouses' => Warehouse::query()->where(fn ($query) => $query->where('status', 'active')
                ->when($data['warehouse_id'] ?? null, fn ($query, $id) => $query->orWhereKey($id)))
                ->orderBy('code')->get(['id', 'code', 'name', 'status']),
            'tiers' => DealerTier::query()->where(fn ($query) => $query->where('status', 'active')
                ->when($selectedTierIds !== [], fn ($query) => $query->orWhereIn('id', $selectedTierIds)))
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'code', 'name', 'status']),
        ]);
    }

    public function save(BladeSaveProductWizardDraftRequest $request, Product $product): RedirectResponse
    {
        abort_if($product->variants()->exists(), 409);
        $data = $request->input('data');
        $product->update([
            'wizard_data' => $data,
            'name' => filled($data['name']) ? $data['name'] : null,
            'product_category_id' => $data['product_category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
        ]);

        return redirect()->route('admin.web.products.drafts.edit', $product)
            ->with('success', 'Đã lưu dữ liệu bản nháp.');
    }

    public function complete(BladeCompleteProductWizardRequest $request, Product $product, ProductWizardService $wizard): RedirectResponse
    {
        abort_if($product->status === 'draft' && $product->variants()->exists(), 409);
        try {
            $result = $wizard->complete($product, $request->validated('data'), $request->user()->id);
        } catch (HttpResponseException $exception) {
            $code = $exception->getResponse()->getData(true)['code'] ?? null;
            $message = $code === 'WIZARD_ALREADY_COMPLETED'
                ? 'Sản phẩm đã hoàn tất với dữ liệu khác. Vui lòng mở chi tiết sản phẩm.'
                : 'Không thể hoàn tất bản nháp. Vui lòng kiểm tra lại dữ liệu.';

            return $product->fresh()?->status === 'active'
                ? redirect()->route('admin.web.products.show', $product)->withErrors(['data' => $message])
                : back()->withInput()->withErrors(['data' => $message]);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }

            return back()->withInput()->withErrors(['data.sku' => 'SKU hoặc giá đã tồn tại. Vui lòng kiểm tra lại và thử lại.']);
        }

        return redirect()->route('admin.web.products.show', $result)
            ->with('success', 'Sản phẩm đã được hoàn tất và kích hoạt.');
    }

    private function assertOwnedDraft(Product $product, Request $request): void
    {
        abort_unless($product->status === 'draft' && $product->wizard_owner_user_id === $request->user()->id, 404);
    }

    private function draftIssue(Product $product): ?string
    {
        if ($product->wizard_key === null) {
            return 'Bản nháp thiếu khóa wizard nên không thể hoàn tất tự động.';
        }
        if ($product->variants->isNotEmpty()) {
            return 'Bản nháp đã có SKU trong cơ sở dữ liệu. Cần kiểm tra trước khi hoàn tất để tránh tạo trùng.';
        }

        return null;
    }
}
