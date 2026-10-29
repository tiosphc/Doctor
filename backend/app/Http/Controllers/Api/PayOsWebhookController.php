<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DealerWalletTopUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayOsWebhookController extends Controller
{
    public function __invoke(Request $request, DealerWalletTopUpService $topUps): JsonResponse
    {
        $topUps->handleWebhook($request->all());

        return response()->json(['success' => true]);
    }
}
