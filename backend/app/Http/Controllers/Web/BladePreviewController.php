<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\DealerContextService;
use App\Services\DealerTierService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BladePreviewController extends Controller
{
    public function admin(): View
    {
        return view('admin.preview');
    }

    public function dealer(Request $request, DealerContextService $dealerContext, DealerTierService $tiers): View
    {
        $account = $dealerContext->primaryAccount($request->user());
        abort_if($account === null, 403, 'Active Dealer membership is required.');

        $account->load('wallet');

        return view('dealer.preview', [
            'account' => $account,
            'effectiveTier' => $tiers->resolve($account)['effective_tier'],
        ]);
    }
}
