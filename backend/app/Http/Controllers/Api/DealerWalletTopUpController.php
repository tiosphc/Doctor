<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Models\DealerWalletTopUpRequest;
use App\Services\DealerContextService;
use App\Services\DealerWalletTopUpService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DealerWalletTopUpController extends Controller
{
    public function index(Request $request, DealerAccount $dealer, DealerContextService $context): JsonResponse
    {
        $context->resolve($request->user(), $dealer);

        return response()->json(DealerWalletTopUpRequest::query()->where('dealer_account_id', $dealer->id)
            ->latest('id')->paginate(10)->through(fn (DealerWalletTopUpRequest $topUp): array => $this->resource($topUp)));
    }

    public function store(Request $request, DealerAccount $dealer, DealerContextService $context, DealerWalletTopUpService $topUps): JsonResponse
    {
        $context->resolve($request->user(), $dealer);
        abort_unless(config('services.payos.top_up_creation_enabled'), 410, 'PAYOS_TOP_UP_CREATION_DISABLED');
        $data = $request->validate([
            'amount' => ['required', 'integer', 'between:2000,1000000000'],
            'operation_key' => ['required', 'uuid'],
        ]);
        $topUp = $topUps->create($dealer, $request->user(), (int) $data['amount'], $data['operation_key']);

        return response()->json(['data' => $this->resource($topUp)], 201);
    }

    public function show(Request $request, DealerAccount $dealer, DealerWalletTopUpRequest $topUp, DealerContextService $context): JsonResponse
    {
        $context->resolve($request->user(), $dealer);
        abort_unless($topUp->dealer_account_id === $dealer->id, 404);

        return response()->json(['data' => $this->resource($topUp)]);
    }

    public function refresh(Request $request, DealerAccount $dealer, DealerWalletTopUpRequest $topUp, DealerContextService $context, DealerWalletTopUpService $topUps): JsonResponse
    {
        $context->resolve($request->user(), $dealer);
        abort_unless($topUp->dealer_account_id === $dealer->id, 404);
        try {
            $topUp = $topUps->refresh($topUp);
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }

        return response()->json(['data' => $this->resource($topUp)]);
    }

    /** @return array<string, mixed> */
    private function resource(DealerWalletTopUpRequest $topUp): array
    {
        return ['top_up_code' => $topUp->top_up_code, ...$topUp->only(['id', 'dealer_account_id', 'amount', 'currency',
            'provider', 'status', 'checkout_url', 'provider_reference', 'created_at', 'expires_at', 'expired_at',
            'failed_at', 'cancelled_at', 'paid_at', 'completed_at'])];
    }
}
