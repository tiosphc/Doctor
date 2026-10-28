<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDealerTierRequest;
use App\Models\DealerTier;
use App\Services\DealerAutoTierService;
use App\Services\DealerTierManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealerTierController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DealerTier::query()->whereIn('code', ['SILVER', 'GOLD', 'DIAMOND'])->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function autoPolicy(DealerAutoTierService $service): JsonResponse
    {
        return response()->json(['data' => $service->policy()]);
    }

    public function setAutoPolicy(Request $request, DealerTierManagementService $management): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        return response()->json(['data' => $management->setAutoPolicy((bool) $data['enabled'], $request->user()->id)]);
    }

    public function update(UpdateDealerTierRequest $request, DealerTier $tier, DealerTierManagementService $management): JsonResponse
    {
        return response()->json(['data' => $management->update($tier, $request->validated())]);
    }
}
