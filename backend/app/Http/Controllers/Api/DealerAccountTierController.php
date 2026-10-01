<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Services\DealerAutoTierService;
use App\Services\DealerContextService;
use App\Services\DealerTierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealerAccountTierController extends Controller
{
    public function show(Request $request, DealerAccount $dealer, DealerContextService $context, DealerTierService $tiers): JsonResponse
    {
        $context->resolve($request->user(), $dealer);

        return response()->json(['data' => $tiers->resolve($dealer)]);
    }

    public function autoTier(Request $request, DealerAccount $dealer, DealerContextService $context, DealerAutoTierService $service): JsonResponse
    {
        $context->resolve($request->user(), $dealer);

        return response()->json(['data' => $service->evaluate($dealer->id)]);
    }
}
