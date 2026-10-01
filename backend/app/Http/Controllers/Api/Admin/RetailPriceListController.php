<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRetailPriceListRequest;
use App\Models\PriceList;
use App\Services\RetailPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RetailPriceListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = PriceList::query()->where('pricing_context', 'retail')->withCount('items');
        if (isset($data['search'])) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$data['search'].'%')->orWhere('code', 'like', '%'.$data['search'].'%'));
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json($query->latest('id')->paginate($data['per_page'] ?? 20));
    }

    public function store(SaveRetailPriceListRequest $request): JsonResponse
    {
        $list = PriceList::create($request->validated());

        return response()->json(['data' => $list], 201);
    }

    public function show(PriceList $priceList): JsonResponse
    {
        abort_unless($priceList->pricing_context === 'retail', 404);

        return response()->json(['data' => $priceList->load(['items.variant:id,product_id,sku,variant_name'])]);
    }

    public function update(SaveRetailPriceListRequest $request, PriceList $priceList, RetailPricingService $pricing): JsonResponse
    {
        abort_unless($priceList->pricing_context === 'retail', 404);
        DB::transaction(function () use ($request, $priceList, $pricing): void {
            PriceList::query()->orderBy('id')->lockForUpdate()->get();
            $priceList->update($request->validated());
            $priceList->refresh()->load('items');
            $pricing->assertValidRange($priceList->effective_from, $priceList->effective_to);
            foreach ($priceList->items as $item) {
                $pricing->assertNoOverlap($item);
            }
        });

        return response()->json(['data' => $priceList->refresh()->load('items')]);
    }
}
