<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveBladeProductRequest;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\Unit;
use App\Services\AdminProductReadService;
use App\Services\AdminProductWriteService;
use App\Services\ProductImageWriteService;
use App\Services\ProductPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class ProductController extends Controller
{
    private const TEMPORARILY_HIDDEN_PRODUCTS = [
        'PRD000017' => 'Cica Repair Cream',
        'PRD000019' => 'Sữa rửa mặt Ceramide dịu nhẹ',
    ];

    public function index(Request $request, AdminProductReadService $products): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:draft,active,inactive'],
            'category' => ['nullable', 'integer', 'exists:product_categories,id'],
            'brand' => ['nullable', 'integer', 'exists:brands,id'],
            'gift_filter' => ['nullable', 'in:all,gift_capable,gift_only,normal'],
        ]);
        $page = $products->paginate($filters, true)->withQueryString();
        $visibleProducts = $page->getCollection()->reject(fn (Product $product): bool => (self::TEMPORARILY_HIDDEN_PRODUCTS[$product->product_code] ?? null) === $product->name
        );

        return view('admin.products.index', [
            'products' => $page,
            'visibleProducts' => $visibleProducts,
            'primaryImages' => $visibleProducts->mapWithKeys(fn (Product $product): array => [
                $product->id => $this->primaryImage($product),
            ]),
            'filters' => $filters,
            'categories' => ProductCategory::query()->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'brands' => Brand::query()->orderBy('name')->orderBy('id')->get(['id', 'name']),
        ]);
    }

    public function show(Product $product, ProductPricingService $pricing): View
    {
        $product->load(['category:id,code,name', 'brand:id,code,name',
            'variants' => fn ($variants) => $variants->orderBy('id'),
            'variants.unit:id,name,symbol',
            'images' => fn ($images) => $images->orderBy('id'),
            'images.variant:id,sku']);

        return view('admin.products.show', [
            'product' => $product,
            'primaryImage' => $this->primaryImage($product),
            'pricing' => $pricing->show($product),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.create', $this->formOptions());
    }

    public function store(SaveBladeProductRequest $request, AdminProductWriteService $writer, ProductImageWriteService $images): RedirectResponse
    {
        $data = $request->validated();
        $variants = $data['variants'];
        unset($data['variants'], $data['images']);
        $variants = array_map(fn (array $variant): array => [
            ...$variant,
            'track_inventory' => (bool) ($data['track_inventory'] ?? true),
            'status' => 'active',
        ], array_values($variants));
        $storedPaths = [];
        try {
            $product = DB::transaction(function () use ($writer, $images, $request, $data, $variants, &$storedPaths): Product {
                $product = $writer->create($data, $variants);
                foreach ($request->file('images', []) as $index => $file) {
                    $image = $images->create($product, $file, ['sort_order' => $index]);
                    $storedPaths[] = $image->path;
                }

                return $product;
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);
            throw $exception;
        }

        return redirect()->route('admin.web.products.show', $product)->with('success', 'Đã tạo sản phẩm.');
    }

    public function edit(Product $product): View|RedirectResponse
    {
        if ($product->status === 'draft') {
            return redirect()->route('admin.web.products.drafts.edit', $product);
        }
        $product->load(['variants' => fn ($variants) => $variants->orderBy('id'), 'variants.unit:id,name,symbol',
            'images' => fn ($images) => $images->orderBy('sort_order')->orderBy('id'), 'images.variant:id,sku']);

        return view('admin.products.edit', [
            ...$this->formOptions($product),
            'product' => $product,
        ]);
    }

    public function update(SaveBladeProductRequest $request, Product $product, AdminProductWriteService $writer): RedirectResponse
    {
        if ($product->status === 'draft') {
            abort_unless($product->wizard_owner_user_id === $request->user()->id, 404);

            return redirect()->route('admin.web.products.drafts.edit', $product)
                ->withErrors(['product' => 'Hãy tiếp tục bản nháp trong trang Product Draft.']);
        }
        $writer->update($product, $request->validated());

        return redirect()->route('admin.web.products.edit', $product)->with('success', 'Đã cập nhật sản phẩm.');
    }

    /** @return array<string, mixed> */
    private function formOptions(?Product $product = null): array
    {
        return [
            'categories' => ProductCategory::query()->where(fn ($query) => $query->where('status', 'active')->when($product?->product_category_id, fn ($query, $id) => $query->orWhere('id', $id)))->orderBy('name')->orderBy('id')->get(['id', 'name', 'status']),
            'brands' => Brand::query()->where(fn ($query) => $query->where('status', 'active')->when($product?->brand_id, fn ($query, $id) => $query->orWhere('id', $id)))->orderBy('name')->orderBy('id')->get(['id', 'name', 'status']),
            'units' => Unit::query()->where(fn ($query) => $query->where('status', 'active')
                ->when($product, fn ($query) => $query->orWhereIn('id', $product->variants->pluck('unit_id'))))
                ->orderBy('name')->orderBy('id')->get(['id', 'name', 'symbol', 'status']),
        ];
    }

    private function primaryImage(Product $product): ?ProductImage
    {
        return $product->images->firstWhere('is_primary', true)
            ?? $product->images->firstWhere('product_variant_id', null)
            ?? $product->images->first();
    }
}
