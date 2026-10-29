<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompleteProductWizardRequest;
use App\Http\Requests\Admin\SaveProductWizardDraftRequest;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\ProductWizardService;
use App\Support\Sku;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductWizardController extends Controller
{
    public function store(SaveProductWizardDraftRequest $request, AuditLogger $audit): JsonResponse
    {
        $key = $request->validated('wizard_key');
        $existing = Product::query()->where('wizard_key', $key)->first();
        if ($existing !== null) {
            $this->assertOwner($existing, $request);

            return response()->json(['data' => $this->loaded($existing)]);
        }
        try {
            $product = DB::transaction(function () use ($request, $key, $audit): Product {
                $data = $request->input('data');
                $product = Product::create([
                    'product_code' => 'TMP-'.Str::uuid(),
                    'slug' => 'draft-'.$key,
                    'name' => filled($data['name'] ?? null) ? trim($data['name']) : null,
                    'product_category_id' => $data['product_category_id'] ?? null,
                    'brand_id' => $data['brand_id'] ?? null,
                    'status' => 'draft',
                    'wizard_key' => $key,
                    'wizard_owner_user_id' => $request->user()->id,
                    'wizard_data' => $data,
                ]);
                $product->update(['product_code' => 'PRD'.str_pad((string) $product->id, 6, '0', STR_PAD_LEFT)]);
                $audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_PRODUCT, $product, 'Created Product wizard draft');

                return $product;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }
            $product = Product::query()->where('wizard_key', $key)->firstOrFail();
            $this->assertOwner($product, $request);
        }

        return response()->json(['data' => $this->loaded($product)], 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->assertOwner($product, $request);

        return response()->json(['data' => $this->loaded($product)]);
    }

    public function skuAvailability(Request $request): JsonResponse
    {
        $data = $request->validate(['sku' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/']]);
        $sku = Sku::normalize($data['sku']);

        return response()->json(['available' => ! Product::query()->where('base_sku', $sku)->exists()
            && ! DB::table('product_variants')->where('sku', $sku)->exists()]);
    }

    public function complete(CompleteProductWizardRequest $request, Product $product, ProductWizardService $wizard): JsonResponse
    {
        $this->assertOwner($product, $request);
        $result = $wizard->complete($product, $request->validated('data'), $request->user()->id);

        return response()->json(['data' => $this->loaded($result)]);
    }

    private function assertOwner(Product $product, Request $request): void
    {
        abort_unless($product->wizard_key !== null && $product->wizard_owner_user_id === $request->user()->id, 404);
    }

    private function loaded(Product $product): Product
    {
        return $product->refresh()->load(['category', 'brand', 'variants.unit', 'images']);
    }
}
