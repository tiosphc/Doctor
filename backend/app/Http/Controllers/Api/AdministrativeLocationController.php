<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeProvince;
use App\Models\AdministrativeWard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdministrativeLocationController extends Controller
{
    public function provinces(): JsonResponse
    {
        return response()->json(['data' => AdministrativeProvince::query()->orderBy('name')->get(['code', 'name'])]);
    }

    public function wards(Request $request): JsonResponse
    {
        $data = $request->validate(['province_code' => ['required', 'exists:administrative_provinces,code']]);

        return response()->json(['data' => AdministrativeWard::query()->where('province_code', $data['province_code'])
            ->orderBy('name')->get(['code', 'province_code', 'name'])]);
    }
}
