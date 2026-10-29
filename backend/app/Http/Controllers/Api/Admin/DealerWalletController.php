<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecordDealerWalletDepositRequest;
use App\Models\DealerAccount;
use App\Models\DealerWallet;
use App\Models\DealerWalletDeposit;
use App\Models\DealerWalletTransaction;
use App\Services\DealerWalletService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealerWalletController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'tier_id' => ['nullable', 'integer', 'exists:dealer_tiers,id'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'inactive'])],
        ]);
        $query = DealerAccount::query()->with('currentTier:id,code,name', 'wallet:id,dealer_account_id,balance,currency')
            ->select('dealer_accounts.*')
            ->selectSub(DealerWalletDeposit::query()->select('amount')
                ->whereColumn('dealer_account_id', 'dealer_accounts.id')->latest('id')->limit(1), 'last_deposit_amount')
            ->selectSub(DealerWalletTransaction::query()->select('dealer_wallet_transactions.created_at')
                ->join('dealer_wallets', 'dealer_wallets.id', '=', 'dealer_wallet_transactions.dealer_wallet_id')
                ->whereColumn('dealer_wallets.dealer_account_id', 'dealer_accounts.id')
                ->orderByDesc('dealer_wallet_transactions.id')->limit(1), 'last_transaction_at');
        $query->when($filters['tier_id'] ?? null, fn ($query, $id) => $query->where('current_tier_id', $id));
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        if (! empty($filters['search'])) {
            $query->where(fn ($query) => $query->where('code', 'like', '%'.$filters['search'].'%')
                ->orWhere('legal_name', 'like', '%'.$filters['search'].'%')
                ->orWhere('phone', 'like', '%'.$filters['search'].'%'));
        }

        return response()->json($query->latest('dealer_accounts.id')->paginate(15)->through(fn (DealerAccount $account): array => [
            'id' => $account->id, 'code' => $account->code, 'legal_name' => $account->legal_name,
            'phone' => $account->phone, 'status' => $account->status,
            'tier' => $account->currentTier?->only(['id', 'code', 'name']),
            'balance' => $account->wallet?->balance ?? '0.00',
            'last_deposit_amount' => $account->last_deposit_amount,
            'last_transaction_at' => $account->last_transaction_at,
        ]));
    }

    public function show(DealerAccount $dealer, DealerWalletService $wallets): JsonResponse
    {
        $wallet = $wallets->ensure($dealer);

        return response()->json(['data' => [
            'dealer_account' => $dealer->only(['id', 'code', 'legal_name', 'status']),
            'currency' => $wallet->currency, 'balance' => $wallet->balance, 'available_balance' => $wallet->balance,
            'total_deposited' => (string) $wallet->transactions()->where('type', 'deposit_credit')->sum('amount'),
            'total_spent' => (string) $wallet->transactions()->where('type', 'order_debit')->sum('amount'),
            'total_refunded' => (string) $wallet->transactions()->where('type', 'refund_credit')->sum('amount'),
            'last_deposit_at' => $wallet->deposits()->latest('id')->value('completed_at'),
            'last_transaction_at' => $wallet->transactions()->latest('id')->value('created_at'),
        ]]);
    }

    public function store(RecordDealerWalletDepositRequest $request, DealerAccount $dealer, DealerWalletService $wallets): JsonResponse
    {
        $deposit = $wallets->recordDeposit($dealer, $request->validated(), $request->user());

        return response()->json(['data' => ['deposit_code' => $deposit->deposit_code, 'amount' => $deposit->amount, 'currency' => $deposit->currency, 'balance' => $deposit->wallet->balance]], 201);
    }

    public function transactions(Request $request, DealerAccount $dealer, DealerWalletService $wallets): JsonResponse
    {
        $wallet = $wallets->ensure($dealer);

        return response()->json($this->transactionQuery($request)->where('dealer_wallet_id', $wallet->id)->latest('id')->paginate(30));
    }

    public function deposits(Request $request, DealerAccount $dealer, DealerWalletService $wallets): JsonResponse
    {
        $wallet = $wallets->ensure($dealer);

        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $query = DealerWalletDeposit::query()->with('recorder:id,name', 'transaction:id,dealer_wallet_deposit_id,transaction_code')
            ->where('dealer_wallet_id', $wallet->id);
        if (! empty($filters['search'])) {
            $query->where(fn ($query) => $query->where('deposit_code', 'like', '%'.$filters['search'].'%')
                ->orWhere('external_reference', 'like', '%'.$filters['search'].'%'));
        }

        return response()->json($query->latest('id')->paginate(30));
    }

    public function allTransactions(Request $request): JsonResponse
    {
        $page = $this->transactionQuery($request)->latest('id')->paginate(30);

        return response()->json([...$page->toArray(), 'summary' => [
            'total_balance' => (string) DealerWallet::query()->sum('balance'),
            'total_deposited' => (string) DealerWalletTransaction::query()->where('type', 'deposit_credit')->sum('amount'),
            'total_spent' => (string) DealerWalletTransaction::query()->where('type', 'order_debit')->sum('amount'),
            'total_refunded' => (string) DealerWalletTransaction::query()->where('type', 'refund_credit')->sum('amount'),
        ]]);
    }

    private function transactionQuery(Request $request): Builder
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'dealer_id' => ['nullable', 'integer', 'exists:dealer_accounts,id'],
            'type' => ['nullable', Rule::in(['deposit_credit', 'order_debit', 'refund_credit', 'adjustment'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $query = DealerWalletTransaction::query()->with('wallet.dealerAccount:id,code,legal_name',
            'deposit:id,deposit_code,method,external_reference,note,recorded_by_user_id,dealer_wallet_top_up_request_id',
            'actor:id,name', 'salesOrder:id,order_code', 'refund:id,refund_code,sales_order_id');
        $query->when($filters['dealer_id'] ?? null, fn ($query, $id) => $query->whereHas('wallet', fn ($wallet) => $wallet->where('dealer_account_id', $id)));
        $query->when($filters['type'] ?? null, fn ($query, $type) => $type === 'adjustment'
            ? $query->whereIn('type', ['adjustment_credit', 'adjustment_debit'])
            : $query->where('type', $type));
        $query->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from));
        $query->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to));
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(fn ($query) => $query->where('transaction_code', 'like', '%'.$search.'%')
                ->orWhereHas('deposit', fn ($deposit) => $deposit->where('external_reference', 'like', '%'.$search.'%')
                    ->orWhere('deposit_code', 'like', '%'.$search.'%'))
                ->orWhereHas('wallet.dealerAccount', fn ($dealer) => $dealer->where('legal_name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')));
        }

        return $query;
    }
}
