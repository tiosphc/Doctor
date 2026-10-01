<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Services\DealerContextService;
use App\Services\DealerWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealerWalletController extends Controller
{
    public function show(Request $request, DealerAccount $dealer, DealerContextService $context, DealerWalletService $wallets): JsonResponse
    {
        $context->resolve($request->user(), $dealer);
        $wallet = $wallets->ensure($dealer);

        return response()->json(['data' => ['dealer_account' => $dealer->only(['id', 'code', 'legal_name']), 'currency' => $wallet->currency, 'balance' => $wallet->balance, 'available_balance' => $wallet->balance]]);
    }

    public function transactions(Request $request, DealerAccount $dealer, DealerContextService $context, DealerWalletService $wallets): JsonResponse
    {
        $context->resolve($request->user(), $dealer);
        $wallet = $wallets->ensure($dealer);
        $page = $wallet->transactions()->latest('id')->paginate(20);

        return response()->json($page);
    }
}
