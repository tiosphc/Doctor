<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoyaltyResource;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function __invoke(Request $request, LoyaltyService $loyaltyService): LoyaltyResource
    {
        return new LoyaltyResource($loyaltyService->summary($request->user()));
    }
}
