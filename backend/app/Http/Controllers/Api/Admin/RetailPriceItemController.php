<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRetailPriceItemRequest;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Services\RetailPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RetailPriceItemController extends Controller
{
    public function store(SaveRetailPriceItemRequest $request, PriceList $priceList, RetailPricingService $pricing): JsonResponse
    {
        $item = DB::transaction(function () use ($request, $priceList, $pricing): PriceListItem {
            PriceList::query()->orderBy('id')->lockForUpdate()->get();
            $item = $priceList->items()->create($request->validated());
            $pricing->assertNoOverlap($item->refresh()->load('priceList'));

            return $item;
        });

        return response()->json(['data' => $item], 201);
    }

    public function update(SaveRetailPriceItemRequest $request, PriceList $priceList, PriceListItem $item, RetailPricingService $pricing): JsonResponse
    {
        abort_unless($item->price_list_id === $priceList->id, 404);
        DB::transaction(function () use ($request, $item, $pricing): void {
            PriceList::query()->orderBy('id')->lockForUpdate()->get();
            $item->update($request->validated());
            $pricing->assertNoOverlap($item->refresh()->load('priceList'));
        });

        return response()->json(['data' => $item->refresh()]);
    }
}
