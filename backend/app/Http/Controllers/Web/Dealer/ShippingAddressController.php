<?php

namespace App\Http\Controllers\Web\Dealer;

use App\Http\Controllers\Controller;
use App\Http\Requests\DealerShippingAddressRequest;
use App\Models\AdministrativeProvince;
use App\Models\AdministrativeWard;
use App\Models\DealerAccount;
use App\Models\User;
use App\Services\DealerContextService;
use App\Services\DealerShippingAddressService;
use App\Services\DealerTierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShippingAddressController extends Controller
{
    public function index(Request $request, DealerContextService $context, DealerShippingAddressService $addresses,
        DealerTierService $tiers): View
    {
        $account = $this->account($request, $context);

        return view('dealer.addresses.index', [
            'addresses' => $addresses->forAccount($account), ...$this->layout($account, $tiers),
        ]);
    }

    public function create(Request $request, DealerContextService $context, DealerTierService $tiers): View
    {
        $account = $this->account($request, $context);

        return view('dealer.addresses.form', [
            'address' => null, ...$this->formData(null), ...$this->layout($account, $tiers),
        ]);
    }

    public function store(DealerShippingAddressRequest $request, DealerContextService $context,
        DealerShippingAddressService $addresses): RedirectResponse
    {
        $account = $this->account($request, $context);
        $addresses->create($account, $request->validated());

        return redirect()->route('dealer.web.addresses.index')->with('success', 'Đã lưu địa chỉ giao hàng.');
    }

    public function edit(Request $request, int $address, DealerContextService $context,
        DealerShippingAddressService $addresses, DealerTierService $tiers): View
    {
        $account = $this->account($request, $context);
        $record = $addresses->findForAccount($account, $address);

        return view('dealer.addresses.form', [
            'address' => $record, ...$this->formData($record->province_code, $record->ward_code),
            ...$this->layout($account, $tiers),
        ]);
    }

    public function update(DealerShippingAddressRequest $request, int $address, DealerContextService $context,
        DealerShippingAddressService $addresses): RedirectResponse
    {
        $account = $this->account($request, $context);
        $addresses->update($account, $address, $request->validated());

        return redirect()->route('dealer.web.addresses.index')->with('success', 'Đã cập nhật địa chỉ giao hàng.');
    }

    public function destroy(Request $request, int $address, DealerContextService $context,
        DealerShippingAddressService $addresses): RedirectResponse
    {
        $account = $this->account($request, $context);
        $addresses->delete($account, $address);

        return redirect()->route('dealer.web.addresses.index')->with('success', 'Đã xóa địa chỉ giao hàng.');
    }

    private function account(Request $request, DealerContextService $context): DealerAccount
    {
        abort_unless($request->user()?->role === User::ROLE_CUSTOMER, 404);
        $account = $context->primaryAccount($request->user());
        abort_if($account === null, 403, 'Cần tài khoản đại lý đang hoạt động.');

        return $account;
    }

    /** @return array<string, mixed> */
    private function formData(?string $provinceCode, ?string $wardCode = null): array
    {
        $selectedProvince = old('province_code', $provinceCode);

        return [
            'provinces' => AdministrativeProvince::query()->orderBy('name')->get(['code', 'name']),
            'wards' => $selectedProvince ? AdministrativeWard::query()->where('province_code', $selectedProvince)
                ->orderBy('name')->get(['code', 'name']) : collect(),
            'selectedProvince' => $selectedProvince,
            'selectedWard' => old('ward_code', $wardCode),
        ];
    }

    /** @return array<string, mixed> */
    private function layout(DealerAccount $account, DealerTierService $tiers): array
    {
        $account->load('wallet');

        return ['account' => $account, 'effectiveTier' => $tiers->resolve($account)['effective_tier']];
    }
}
