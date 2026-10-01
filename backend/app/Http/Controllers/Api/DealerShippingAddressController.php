<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Models\DealerShippingAddress;
use App\Services\DealerContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DealerShippingAddressController extends Controller
{
    public function index(Request $request, DealerAccount $dealer, DealerContextService $context): JsonResponse
    {
        $context->resolve($request->user(), $dealer);

        return response()->json(['data' => DealerShippingAddress::query()->with(['province', 'ward'])
            ->where('dealer_account_id', $dealer->id)->orderByDesc('is_default')->latest('id')->get()]);
    }

    public function store(Request $request, DealerAccount $dealer, DealerContextService $context): JsonResponse
    {
        $context->resolve($request->user(), $dealer);
        $data = $this->validated($request);
        $address = DB::transaction(function () use ($dealer, $data): DealerShippingAddress {
            $isDefault = (bool) ($data['is_default'] ?? false)
                || ! DealerShippingAddress::query()->where('dealer_account_id', $dealer->id)->exists();
            if ($isDefault) {
                DealerShippingAddress::query()->where('dealer_account_id', $dealer->id)->update(['is_default' => false]);
            }

            return DealerShippingAddress::query()->create([...$data,
                'dealer_account_id' => $dealer->id, 'is_default' => $isDefault]);
        });

        return response()->json(['data' => $address->load(['province', 'ward'])], 201);
    }

    public function update(Request $request, DealerAccount $dealer, DealerShippingAddress $address, DealerContextService $context): JsonResponse
    {
        $context->resolve($request->user(), $dealer);
        abort_unless($address->dealer_account_id === $dealer->id, 404);
        $data = $this->validated($request);
        DB::transaction(function () use ($dealer, $address, $data): void {
            if ($data['is_default'] ?? false) {
                DealerShippingAddress::query()->where('dealer_account_id', $dealer->id)->update(['is_default' => false]);
            }
            $address->update($data);
        });

        return response()->json(['data' => $address->refresh()->load(['province', 'ward'])]);
    }

    public function destroy(Request $request, DealerAccount $dealer, DealerShippingAddress $address, DealerContextService $context): JsonResponse
    {
        $context->resolve($request->user(), $dealer);
        abort_unless($address->dealer_account_id === $dealer->id, 404);
        $address->delete();

        return response()->json(['message' => 'Đã xóa địa chỉ giao hàng.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_phone' => ['required', 'string', 'max:50', 'regex:/^(?=.*[0-9])[+0-9().\-\s]+$/'],
            'address_line' => ['required', 'string', 'max:255'],
            'province_code' => ['required', Rule::exists('administrative_provinces', 'code')],
            'ward_code' => ['required', Rule::exists('administrative_wards', 'code')
                ->where('province_code', $request->input('province_code'))],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }
}
