<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDealerTierRequest;
use App\Models\DealerTier;
use App\Services\DealerAutoTierService;
use App\Services\DealerTierManagementService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DealerTierController extends Controller
{
    public function index(DealerAutoTierService $autoTiers): View
    {
        return view('admin.dealer-tiers.index', [
            'tiers' => DealerTier::query()->whereIn('code', ['SILVER', 'GOLD', 'DIAMOND'])
                ->orderBy('sort_order')->orderBy('id')->get(),
            'policy' => $autoTiers->policy(),
        ]);
    }

    public function update(UpdateDealerTierRequest $request, DealerTier $tier, DealerTierManagementService $management): RedirectResponse
    {
        abort_unless(in_array($tier->code, ['SILVER', 'GOLD', 'DIAMOND'], true), 404);
        $this->runDomain(fn () => $management->update($tier, $request->validated()));

        return redirect()->route('admin.web.dealer-tiers.index')->with('success', 'Đã cập nhật Tier đại lý.');
    }

    public function setAutoPolicy(Request $request, DealerTierManagementService $management): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->runDomain(fn () => $management->setAutoPolicy((bool) $data['enabled'], $request->user()->id));

        return redirect()->route('admin.web.dealer-tiers.index')->with('success', 'Đã cập nhật chính sách xét Tier tự động.');
    }

    private function runDomain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $code = $exception->getResponse()->getData(true)['code'] ?? 'DEALER_TIER_CONFLICT';
            throw ValidationException::withMessages(['operation' => 'Không thể cập nhật Tier ('.$code.'). Kiểm tra ngưỡng và trạng thái hiện tại.']);
        }
    }
}
