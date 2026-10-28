<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\DealerWalletDepositRequest;
use App\Models\DealerWalletTopUpRequest;
use App\Services\DealerWalletDepositRequestService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DealerWalletDepositRequestController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'search' => ['nullable', 'string', 'max:100'],
            'dealer_id' => ['nullable', 'integer', 'exists:dealer_accounts,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $query = DealerWalletDepositRequest::query()->with(['dealerAccount:id,code,legal_name,current_tier_id',
            'dealerAccount.currentTier:id,code,name', 'reviewer:id,name']);
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $query->when($filters['dealer_id'] ?? null, fn ($query, $id) => $query->where('dealer_account_id', $id));
        $query->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from));
        $query->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to));
        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $query->where(fn ($query) => $query->where('request_code', 'like', '%'.$search.'%')
                ->orWhereHas('dealerAccount', fn ($account) => $account->where('code', 'like', '%'.$search.'%')
                    ->orWhere('legal_name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')));
        }

        return view('admin.dealer-wallet-deposits.index', [
            'requests' => $query->latest('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'pendingCount' => DealerWalletDepositRequest::query()->where('status', 'pending')->count(),
            'legacyTopUps' => DealerWalletTopUpRequest::query()->with('dealerAccount:id,code,legal_name')
                ->latest('id')->paginate(10, ['*'], 'topups_page'),
        ]);
    }

    public function show(DealerWalletDepositRequest $depositRequest): View
    {
        $depositRequest->load(['dealerAccount:id,code,legal_name,status,current_tier_id',
            'dealerAccount.currentTier:id,code,name', 'wallet:id,dealer_account_id,balance,currency',
            'reviewer:id,name', 'transaction:id,transaction_code,amount,balance_after']);

        return view('admin.dealer-wallet-deposits.show', ['depositRequest' => $depositRequest]);
    }

    public function proof(DealerWalletDepositRequest $depositRequest): StreamedResponse
    {
        return Storage::disk('local')->response($depositRequest->payment_proof_path, $depositRequest->request_code, [
            'Content-Disposition' => 'inline', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(Request $request, DealerWalletDepositRequest $depositRequest, DealerWalletDepositRequestService $deposits): RedirectResponse
    {
        $this->runDomain(fn () => $deposits->approve($depositRequest, $request->user()));

        return redirect()->route('admin.web.dealer-wallet-deposits.show', $depositRequest)
            ->with('success', 'Đã xác nhận yêu cầu và ghi có ví đại lý.');
    }

    public function reject(Request $request, DealerWalletDepositRequest $depositRequest, DealerWalletDepositRequestService $deposits): RedirectResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $this->runDomain(fn () => $deposits->reject($depositRequest, $request->user(), $data['rejection_reason']));

        return redirect()->route('admin.web.dealer-wallet-deposits.show', $depositRequest)
            ->with('success', 'Đã từ chối yêu cầu nạp tiền. Số dư ví không thay đổi.');
    }

    private function runDomain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $code = $exception->getResponse()->getData(true)['code'] ?? 'DEALER_WALLET_DEPOSIT_REQUEST_CONFLICT';
            throw ValidationException::withMessages(['operation' => 'Không thể xét duyệt ('.$code.'). Hãy tải lại trạng thái yêu cầu.']);
        }
    }
}
