<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DealerWalletTopUpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealerWalletTopUpController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:initiating,pending,paid,expired,failed,cancelled'],
            'dealer_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $query = DealerWalletTopUpRequest::query()->with('dealerAccount:id,code,legal_name')->latest('id');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['dealer_id'])) {
            $query->where('dealer_account_id', $filters['dealer_id']);
        }
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->whereHas('dealerAccount', fn ($dealer) => $dealer->where('code', 'like', '%'.$search.'%')
                ->orWhere('legal_name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%'));
        }
        $query->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from));
        $query->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to));

        return response()->json($query->paginate(30)->through(fn (DealerWalletTopUpRequest $topUp): array => [
            'id' => $topUp->id,
            'top_up_code' => $topUp->top_up_code,
            'dealer_account' => $topUp->dealerAccount?->only(['id', 'code', 'legal_name']),
            'amount' => $topUp->amount,
            'currency' => $topUp->currency,
            'provider' => $topUp->provider,
            'status' => $topUp->status,
            'provider_reference' => $topUp->provider_reference,
            'provider_payment_link_id' => $topUp->provider_payment_link_id,
            'created_at' => $topUp->created_at,
            'paid_at' => $topUp->paid_at,
            'completed_at' => $topUp->completed_at,
            'expires_at' => $topUp->expires_at,
            'expired_at' => $topUp->expired_at,
            'failed_at' => $topUp->failed_at,
            'cancelled_at' => $topUp->cancelled_at,
        ]));
    }
}
