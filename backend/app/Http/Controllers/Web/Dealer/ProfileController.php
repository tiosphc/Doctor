<?php

namespace App\Http\Controllers\Web\Dealer;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DealerContextService;
use App\Services\DealerTierService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function index(Request $request, DealerContextService $context, DealerTierService $tiers): View
    {
        abort_unless($request->user()?->role === User::ROLE_CUSTOMER, 404);
        $account = $context->primaryAccount($request->user());
        abort_if($account === null, 403, 'Cần tài khoản đại lý đang hoạt động.');
        $membership = $context->resolve($request->user(), $account);
        $account->load(['wallet', 'currentTier']);
        $resolution = $tiers->resolve($account);

        return view('dealer.profile.index', [
            'account' => $account,
            'effectiveTier' => $resolution['effective_tier'],
            'tierResolution' => $resolution,
            'membership' => $membership,
            'tierHistories' => $account->tierHistories()->with(['previousTier:id,name,code', 'newTier:id,name,code'])
                ->latest('id')->paginate(10),
        ]);
    }
}
