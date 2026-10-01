<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewDealerQuickOrderRequest;
use App\Http\Requests\SubmitDealerQuickOrderRequest;
use App\Http\Resources\DealerSalesOrderResource;
use App\Models\DealerAccount;
use App\Services\DealerContextService;
use App\Services\DealerQuickOrderService;
use Illuminate\Http\JsonResponse;

class DealerQuickOrderController extends Controller
{
    public function review(ReviewDealerQuickOrderRequest $request, DealerAccount $dealer, DealerQuickOrderService $service, DealerContextService $context): JsonResponse
    {
        abort_unless($context->primaryAccount($request->user())?->id === $dealer->id, 404);

        return response()->json(['data' => $service->review($request->user(), $dealer,
            $request->validated('items'), $request->validated())]);
    }

    public function store(SubmitDealerQuickOrderRequest $request, DealerAccount $dealer, DealerQuickOrderService $service, DealerContextService $context): JsonResponse
    {
        abort_unless($context->primaryAccount($request->user())?->id === $dealer->id, 404);

        $order = $service->submit($request->user(), $dealer, $request->validated());

        return (new DealerSalesOrderResource($order->load(['buyer:id,name', 'warehouse:id,code,name', 'items'])))
            ->response()->setStatusCode(201);
    }
}
