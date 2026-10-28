<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDealerAccountRequest;
use App\Models\AuditLog;
use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\DealerTierOverride;
use App\Models\SalesOrder;
use App\Services\DealerAccountService;
use App\Services\DealerAutoTierService;
use App\Services\DealerTierService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DealerAccountController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'suspended', 'inactive'])],
            'search' => ['nullable', 'string', 'max:100'],
            'tier_id' => ['nullable', 'integer', 'exists:dealer_tiers,id'],
        ]);
        $query = DealerAccount::query()->with([
            'memberships' => fn ($query) => $query->where('membership_role', 'owner')->with('user:id,name,email'),
            'currentTier:id,code,name', 'wallet:id,dealer_account_id,balance',
        ])->withCount('memberships');
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['tier_id'] ?? null, fn ($query, $tier) => $query->where('current_tier_id', $tier));
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];
            $query->where(fn ($query) => $query->where('code', 'like', '%'.$search.'%')
                ->orWhere('legal_name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%'));
        }

        return view('admin.dealers.index', [
            'dealers' => $query->latest('id')->paginate(15)->withQueryString(),
            'filters' => $filters,
            'tiers' => DealerTier::query()->whereIn('code', ['SILVER', 'GOLD', 'DIAMOND'])->orderBy('sort_order')->get(['id', 'name', 'code']),
        ]);
    }

    public function show(DealerAccount $dealer, DealerTierService $tiers, DealerAutoTierService $autoTiers): View
    {
        $dealer->load(['application:id,user_id,company_name,status', 'currentTier:id,code,name', 'wallet:id,dealer_account_id,balance']);

        return view('admin.dealers.show', [
            'dealer' => $dealer,
            'memberships' => $dealer->memberships()->with('user:id,name,email')->orderBy('id')->paginate(10, ['*'], 'members_page'),
            'tierResolution' => $tiers->resolve($dealer),
            'tierHistories' => $dealer->tierHistories()->with(['previousTier:id,code,name', 'newTier:id,code,name', 'actor:id,name'])
                ->latest('id')->limit(20)->get(),
            'tierOverrides' => $dealer->tierOverrides()->with(['tier:id,code,name', 'creator:id,name'])
                ->latest('id')->limit(20)->get(),
            'history' => AuditLog::query()->where('target_type', 'DealerAccount')->where('target_id', $dealer->id)
                ->latest('id')->limit(20)->get(),
            'orderCount' => SalesOrder::query()->where('dealer_account_id', $dealer->id)->count(),
            'tiers' => DealerTier::query()->whereIn('code', ['SILVER', 'GOLD', 'DIAMOND'])->where('status', 'active')
                ->orderBy('sort_order')->get(['id', 'code', 'name']),
            'autoPolicy' => $autoTiers->policy(),
            'operationKey' => old('operation_key', (string) Str::uuid()),
        ]);
    }

    public function update(UpdateDealerAccountRequest $request, DealerAccount $dealer, DealerAccountService $service): RedirectResponse
    {
        $this->runDomain(fn () => $service->update($dealer, $request->validated(), $request->user()));

        return $this->done($dealer, 'Đã cập nhật thông tin đại lý.');
    }

    public function suspend(Request $request, DealerAccount $dealer, DealerAccountService $service): RedirectResponse
    {
        return $this->transition($request, $dealer, $service, DealerAccount::STATUS_SUSPENDED);
    }

    public function activate(Request $request, DealerAccount $dealer, DealerAccountService $service): RedirectResponse
    {
        return $this->transition($request, $dealer, $service, DealerAccount::STATUS_ACTIVE);
    }

    public function inactivate(Request $request, DealerAccount $dealer, DealerAccountService $service): RedirectResponse
    {
        return $this->transition($request, $dealer, $service, DealerAccount::STATUS_INACTIVE);
    }

    public function changeTier(Request $request, DealerAccount $dealer, DealerTierService $service): RedirectResponse
    {
        $data = $request->validate([
            'tier_id' => ['required', 'integer', 'exists:dealer_tiers,id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'operation_key' => ['required', 'uuid'],
        ]);
        $this->runDomain(fn () => $service->change($dealer, DealerTier::findOrFail($data['tier_id']),
            trim($data['reason']), $data['operation_key'], $request->user()));

        return $this->done($dealer, 'Đã thay đổi Tier đại lý.');
    }

    public function createOverride(Request $request, DealerAccount $dealer, DealerTierService $service): RedirectResponse
    {
        $data = $request->validate([
            'tier_id' => ['required', 'integer', 'exists:dealer_tiers,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $this->runDomain(fn () => $service->createOverride($dealer, DealerTier::findOrFail($data['tier_id']),
            CarbonImmutable::parse($data['starts_at']), isset($data['ends_at']) ? CarbonImmutable::parse($data['ends_at']) : null,
            trim($data['reason']), $request->user()));

        return $this->done($dealer, 'Đã tạo ngoại lệ Tier.');
    }

    public function cancelOverride(Request $request, DealerAccount $dealer, DealerTierOverride $override, DealerTierService $service): RedirectResponse
    {
        $this->runDomain(fn () => $service->cancelOverride($dealer, $override, $request->user()));

        return $this->done($dealer, 'Đã hủy ngoại lệ Tier.');
    }

    private function transition(Request $request, DealerAccount $dealer, DealerAccountService $service, string $target): RedirectResponse
    {
        $this->runDomain(fn () => $service->transition($dealer, $target, $request->user()));

        return $this->done($dealer, 'Đã thay đổi trạng thái đại lý.');
    }

    private function done(DealerAccount $dealer, string $message): RedirectResponse
    {
        return redirect()->route('admin.web.dealers.show', $dealer)->with('success', $message);
    }

    private function runDomain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $code = $exception->getResponse()->getData(true)['code'] ?? 'DEALER_ACCOUNT_CONFLICT';
            throw ValidationException::withMessages(['operation' => 'Không thể cập nhật ('.$code.'). Hãy tải lại thông tin đại lý.']);
        }
    }
}
