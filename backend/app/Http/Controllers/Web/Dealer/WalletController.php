<?php

namespace App\Http\Controllers\Web\Dealer;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDealerWalletDepositRequest;
use App\Models\DealerAccount;
use App\Models\DealerWallet;
use App\Models\DealerWalletDepositRequest;
use App\Models\DealerWalletTopUpRequest;
use App\Models\User;
use App\Services\DealerContextService;
use App\Services\DealerTierService;
use App\Services\DealerWalletDepositRequestService;
use App\Services\DealerWalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WalletController extends Controller
{
    public function index(Request $request, DealerContextService $context, DealerWalletService $wallets, DealerTierService $tiers): View
    {
        $account = $this->account($request, $context);
        $wallet = $wallets->ensure($account);

        return view('dealer.wallet.index', [
            'wallet' => $wallet,
            'transactions' => $wallet->transactions()->with(['deposit:id,deposit_code,method,external_reference',
                'salesOrder:id,order_code', 'refund:id,refund_code'])->latest('id')
                ->paginate(20, ['*'], 'transactions_page'),
            'requests' => DealerWalletDepositRequest::query()->where('dealer_account_id', $account->id)
                ->latest('id')->paginate(10, ['*'], 'requests_page'),
            'legacyTopUps' => DealerWalletTopUpRequest::query()->where('dealer_account_id', $account->id)
                ->latest('id')->paginate(10, ['*'], 'topups_page'),
            ...$this->layout($account, $wallet, $tiers),
        ]);
    }

    public function showRequest(Request $request, int $depositRequest, DealerContextService $context,
        DealerWalletService $wallets, DealerTierService $tiers): View
    {
        $account = $this->account($request, $context);
        $record = DealerWalletDepositRequest::query()->whereKey($depositRequest)
            ->where('dealer_account_id', $account->id)->with('transaction:id,transaction_code,amount')->firstOrFail();
        $wallet = $wallets->ensure($account);

        return view('dealer.wallet.show-request', [
            'depositRequest' => $record,
            'wallet' => $wallet,
            ...$this->layout($account, $wallet, $tiers),
        ]);
    }

    public function store(CreateDealerWalletDepositRequest $request, DealerContextService $context,
        DealerWalletDepositRequestService $deposits): RedirectResponse
    {
        $account = $this->account($request, $context);
        $depositRequest = $deposits->create($account, $request->user(), $request->validated());

        return redirect()->route('dealer.web.wallet.requests.show', $depositRequest->id)
            ->with('success', 'Đã gửi yêu cầu nạp tiền. Ví chỉ được ghi có sau khi Admin xác nhận.');
    }

    public function proof(Request $request, int $depositRequest, DealerContextService $context): StreamedResponse
    {
        $account = $this->account($request, $context);
        $record = DealerWalletDepositRequest::query()->whereKey($depositRequest)
            ->where('dealer_account_id', $account->id)->firstOrFail();

        return Storage::disk('local')->response($record->payment_proof_path, $record->request_code, [
            'Content-Disposition' => 'inline', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function account(Request $request, DealerContextService $context): DealerAccount
    {
        abort_unless($request->user()?->role === User::ROLE_CUSTOMER, 404);
        $account = $context->primaryAccount($request->user());
        abort_if($account === null, 403, 'Cần tư cách đại lý đang hoạt động.');

        return $account;
    }

    /** @return array<string, mixed> */
    private function layout(DealerAccount $account, DealerWallet $wallet, DealerTierService $tiers): array
    {
        $account->setRelation('wallet', $wallet);

        return ['account' => $account, 'effectiveTier' => $tiers->resolve($account)['effective_tier']];
    }
}
