<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecordDealerWalletDepositRequest;
use App\Models\DealerAccount;
use App\Models\DealerWallet;
use App\Models\DealerWalletDeposit;
use App\Models\DealerWalletTransaction;
use App\Services\DealerWalletService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DealerWalletController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'dealer_id' => ['nullable', 'integer', 'exists:dealer_accounts,id'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'inactive'])],
        ]);
        $query = DealerAccount::query()->with(['wallet:id,dealer_account_id,currency,balance', 'currentTier:id,code,name']);
        $query->when($filters['dealer_id'] ?? null, fn ($query, $id) => $query->whereKey($id));
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $query->where(fn ($query) => $query->where('code', 'like', '%'.$search.'%')
                ->orWhere('legal_name', 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.$search.'%'));
        }

        return view('admin.dealer-wallets.index', [
            'accounts' => $query->latest('id')->paginate(15)->withQueryString(),
            'filters' => $filters,
            'summary' => $this->summary(),
            'recentTransactions' => $this->transactionQuery([])->latest('id')->limit(8)->get(),
        ]);
    }

    public function transactions(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'dealer_id' => ['nullable', 'integer', 'exists:dealer_accounts,id'],
            'type' => ['nullable', Rule::in(['deposit_credit', 'order_debit', 'refund_credit'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return view('admin.dealer-wallets.transactions', [
            'transactions' => $this->transactionQuery($filters)->latest('id')->paginate(30)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function show(DealerAccount $dealer, DealerWalletService $wallets): View
    {
        $wallet = $wallets->ensure($dealer);
        $dealer->load('currentTier:id,code,name');

        return view('admin.dealer-wallets.show', [
            'dealer' => $dealer,
            'wallet' => $wallet,
            'transactions' => $this->transactionQuery([])->where('dealer_wallet_id', $wallet->id)
                ->latest('id')->paginate(20, ['*'], 'transactions_page'),
            'deposits' => DealerWalletDeposit::query()->where('dealer_wallet_id', $wallet->id)
                ->with(['recorder:id,name', 'transaction:id,dealer_wallet_deposit_id,transaction_code'])
                ->latest('id')->paginate(10, ['*'], 'deposits_page'),
            'totalDeposited' => (string) $wallet->transactions()->where('type', 'deposit_credit')->sum('amount'),
            'totalSpent' => (string) $wallet->transactions()->where('type', 'order_debit')->sum('amount'),
            'totalRefunded' => (string) $wallet->transactions()->where('type', 'refund_credit')->sum('amount'),
            'operationKey' => old('operation_key', (string) Str::uuid()),
        ]);
    }

    public function store(RecordDealerWalletDepositRequest $request, DealerAccount $dealer, DealerWalletService $wallets): RedirectResponse
    {
        try {
            $wallets->recordDeposit($dealer, $request->validated(), $request->user());
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $code = $exception->getResponse()->getData(true)['code'] ?? 'DEALER_WALLET_DEPOSIT_CONFLICT';
            throw ValidationException::withMessages(['operation' => 'Không thể ghi nhận tiền nạp ('.$code.'). Kiểm tra trạng thái và mã tham chiếu.']);
        }

        return redirect()->route('admin.web.dealer-wallets.show', $dealer)->with('success', 'Đã ghi nhận tiền nạp vào ví đại lý.');
    }

    /** @param array<string, mixed> $filters */
    private function transactionQuery(array $filters): Builder
    {
        $query = DealerWalletTransaction::query()->with([
            'wallet.dealerAccount:id,code,legal_name', 'deposit:id,deposit_code,method,external_reference,note',
            'actor:id,name', 'salesOrder:id,order_code', 'refund:id,refund_code,sales_order_id',
        ]);
        $query->when($filters['dealer_id'] ?? null, fn ($query, $id) => $query->whereHas('wallet', fn ($wallet) => $wallet->where('dealer_account_id', $id)));
        $query->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type));
        $query->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from));
        $query->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to));
        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $query->where(fn ($query) => $query->where('transaction_code', 'like', '%'.$search.'%')
                ->orWhereHas('deposit', fn ($deposit) => $deposit->where('deposit_code', 'like', '%'.$search.'%')
                    ->orWhere('external_reference', 'like', '%'.$search.'%'))
                ->orWhereHas('wallet.dealerAccount', fn ($account) => $account->where('code', 'like', '%'.$search.'%')
                    ->orWhere('legal_name', 'like', '%'.$search.'%')));
        }

        return $query;
    }

    /** @return array<string, string> */
    private function summary(): array
    {
        return [
            'balance' => (string) DealerWallet::query()->sum('balance'),
            'deposited' => (string) DealerWalletTransaction::query()->where('type', 'deposit_credit')->sum('amount'),
            'spent' => (string) DealerWalletTransaction::query()->where('type', 'order_debit')->sum('amount'),
            'refunded' => (string) DealerWalletTransaction::query()->where('type', 'refund_credit')->sum('amount'),
        ];
    }
}
