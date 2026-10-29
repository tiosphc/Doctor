<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Models\DealerWalletDepositRequest;
use App\Services\DealerContextService;
use App\Services\DealerWalletDepositRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DealerWalletDepositRequestController extends Controller
{
    public function index(Request $request, DealerAccount $dealer, DealerContextService $context): JsonResponse
    {
        $context->resolve($request->user(), $dealer);

        return response()->json(DealerWalletDepositRequest::query()->where('dealer_account_id', $dealer->id)
            ->latest('id')->paginate(10)->through(fn (DealerWalletDepositRequest $item): array => $this->resource($item)));
    }

    public function store(Request $request, DealerAccount $dealer, DealerContextService $context, DealerWalletDepositRequestService $requests): JsonResponse
    {
        $context->resolve($request->user(), $dealer);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'between:1,1000000000000'],
            'payment_proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'status' => ['prohibited'],
            'dealer_account_id' => ['prohibited'],
            'dealer_wallet_id' => ['prohibited'],
        ]);
        $depositRequest = $requests->create($dealer, $request->user(), $data);

        return response()->json(['data' => $this->resource($depositRequest)], 201);
    }

    public function proof(Request $request, DealerAccount $dealer, DealerWalletDepositRequest $depositRequest, DealerContextService $context): StreamedResponse
    {
        $context->resolve($request->user(), $dealer);
        abort_unless($depositRequest->dealer_account_id === $dealer->id, 404);

        return Storage::disk('local')->response($depositRequest->payment_proof_path, $depositRequest->request_code, ['Content-Disposition' => 'inline']);
    }

    /** @return array<string, mixed> */
    private function resource(DealerWalletDepositRequest $item): array
    {
        return [
            'id' => $item->id,
            'request_code' => $item->request_code,
            'dealer_account_id' => $item->dealer_account_id,
            'amount' => $item->amount,
            'status' => $item->status,
            'transaction_reference' => $item->transaction_reference,
            'note' => $item->note,
            'rejection_reason' => $item->rejection_reason,
            'created_at' => $item->created_at,
            'reviewed_at' => $item->reviewed_at,
        ];
    }
}
