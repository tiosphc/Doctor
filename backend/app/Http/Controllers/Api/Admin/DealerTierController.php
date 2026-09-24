<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DealerTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealerTierController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DealerTier::query()->orderBy('name')->orderBy('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->has('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', 'unique:dealer_tiers,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $tier = DealerTier::create([...$data, 'status' => 'active']);

        return response()->json(['data' => $tier], 201);
    }

    public function update(Request $request, DealerTier $tier): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $tier->update($data);

        return response()->json(['data' => $tier->refresh()]);
    }
}
