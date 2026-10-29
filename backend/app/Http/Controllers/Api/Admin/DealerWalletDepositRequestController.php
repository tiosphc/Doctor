<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DealerWalletDepositRequest;
use App\Services\DealerWalletDepositRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DealerWalletDepositRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $query = DealerWalletDepositRequest::query()->with('dealerAccount.currentTier:id,code,name')->latest('id');
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $query->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from));
        $query->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to));
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(fn ($query) => $query->where('request_code', 'like', '%'.$search.'%')
                ->orWhereHas('dealerAccount', fn ($dealer) => $dealer->where('legal_name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')));
        }
        $page = $query->paginate(30)->through(fn (DealerWalletDepositRequest $item): array => $this->resource($item));

        return response()->json([...$page->toArray(), 'pending_count' => DealerWalletDepositRequest::query()->where('status', 'pending')->count()]);
    }

    public function show(DealerWalletDepositRequest $depositRequest): JsonResponse
    {
        return response()->json(['data' => $this->resource($depositRequest->load('dealerAccount.currentTier', 'wallet', 'reviewer', 'transaction'), true)]);
    }

    public function proof(DealerWalletDepositRequest $depositRequest): StreamedResponse
    {
        return Storage::disk('local')->response($depositRequest->payment_proof_path, $depositRequest->request_code, ['Content-Disposition' => 'inline']);
    }

    public function approve(DealerWalletDepositRequest $depositRequest, DealerWalletDepositRequestService $requests, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->resource($requests->approve($depositRequest, $request->user())->load('dealerAccount.currentTier', 'wallet', 'reviewer', 'transaction'), true)]);
    }

    public function reject(DealerWalletDepositRequest $depositRequest, DealerWalletDepositRequestService $requests, Request $request): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => $this->resource($requests->reject($depositRequest, $request->user(), $data['rejection_reason'])->load('dealerAccount.currentTier', 'wallet', 'reviewer', 'transaction'), true)]);
    }

    /** @return array<string, mixed> */
    private function resource(DealerWalletDepositRequest $item, bool $detail = false): array
    {
        $account = $item->dealerAccount;
        $data = [
            'id' => $item->id,
            'request_code' => $item->request_code,
            'dealer_account' => ['id' => $account->id, 'code' => $account->code, 'legal_name' => $account->legal_name,
                'tier' => $account->currentTier?->only(['id', 'code', 'name'])],
            'amount' => $item->amount,
            'status' => $item->status,
            'transaction_reference' => $item->transaction_reference,
            'created_at' => $item->created_at,
        ];
        if ($detail) {
            $data += [
                'wallet_balance' => $item->wallet->balance,
                'note' => $item->note,
                'rejection_reason' => $item->rejection_reason,
                'reviewed_by' => $item->reviewer?->only(['id', 'name']),
                'reviewed_at' => $item->reviewed_at,
                'wallet_transaction_id' => $item->dealer_wallet_transaction_id,
                'wallet_transaction_code' => $item->transaction?->transaction_code,
            ];
        }

        return $data;
    }
}
