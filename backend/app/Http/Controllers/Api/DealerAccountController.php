<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DealerAccountResource;
use App\Models\DealerAccount;
use App\Services\DealerContextService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DealerAccountController extends Controller
{
    public function index(Request $request, DealerContextService $context): AnonymousResourceCollection
    {
        $memberships = $context->activeMemberships($request->user());
        $accounts = $memberships->map(function ($membership) {
            $membership->account->setAttribute('membership_role', $membership->membership_role);

            return $membership->account;
        });

        return DealerAccountResource::collection($accounts);
    }

    public function show(Request $request, DealerAccount $dealer, DealerContextService $context): DealerAccountResource
    {
        $membership = $context->resolve($request->user(), $dealer);
        $dealer->setAttribute('membership_role', $membership->membership_role);

        return new DealerAccountResource($dealer);
    }
}
