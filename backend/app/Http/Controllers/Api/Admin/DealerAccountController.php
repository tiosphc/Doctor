<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDealerAccountRequest;
use App\Http\Resources\DealerAccountResource;
use App\Models\DealerAccount;
use App\Services\DealerAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DealerAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['status' => ['nullable', 'in:active,suspended,inactive'], 'search' => ['nullable', 'string', 'max:100']]);
        $query = DealerAccount::query()->with([
            'memberships' => fn ($query) => $query->where('membership_role', 'owner')->with('user:id,name,email'),
            'currentTier:id,code,name',
            'wallet:id,dealer_account_id,balance',
        ]);
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($query) => $query->where('code', 'like', '%'.$search.'%')
                ->orWhere('legal_name', 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.$search.'%'));
        }

        return DealerAccountResource::collection($query->latest('id')->paginate(15)->withQueryString());
    }

    public function show(DealerAccount $dealer): DealerAccountResource
    {
        return new DealerAccountResource($dealer->load('memberships.user:id,name,email'));
    }

    public function update(UpdateDealerAccountRequest $request, DealerAccount $dealer, DealerAccountService $service): DealerAccountResource
    {
        return new DealerAccountResource($service->update($dealer, $request->validated(), $request->user())->load('memberships.user:id,name,email'));
    }

    public function suspend(Request $request, DealerAccount $dealer, DealerAccountService $service): DealerAccountResource
    {
        return $this->transition($request, $dealer, $service, DealerAccount::STATUS_SUSPENDED);
    }

    public function activate(Request $request, DealerAccount $dealer, DealerAccountService $service): DealerAccountResource
    {
        return $this->transition($request, $dealer, $service, DealerAccount::STATUS_ACTIVE);
    }

    public function inactivate(Request $request, DealerAccount $dealer, DealerAccountService $service): DealerAccountResource
    {
        return $this->transition($request, $dealer, $service, DealerAccount::STATUS_INACTIVE);
    }

    private function transition(Request $request, DealerAccount $dealer, DealerAccountService $service, string $target): DealerAccountResource
    {
        return new DealerAccountResource($service->transition($dealer, $target, $request->user())->load('memberships.user:id,name,email'));
    }
}
