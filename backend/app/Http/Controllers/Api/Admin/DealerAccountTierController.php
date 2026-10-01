<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DealerTierHistoryResource;
use App\Models\DealerAccount;
use App\Models\DealerTier;
use App\Models\DealerTierOverride;
use App\Services\DealerAutoTierService;
use App\Services\DealerTierService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DealerAccountTierController extends Controller
{
    public function show(DealerAccount $dealer, DealerTierService $service): JsonResponse
    {
        return response()->json(['data' => $service->resolve($dealer)]);
    }

    public function autoTier(DealerAccount $dealer, DealerAutoTierService $service): JsonResponse
    {
        return response()->json(['data' => $service->evaluate($dealer->id)]);
    }

    public function change(Request $request, DealerAccount $dealer, DealerTierService $service): JsonResponse
    {
        $data = $request->validate([
            'tier_id' => ['required', 'integer', 'exists:dealer_tiers,id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'operation_key' => ['required', 'uuid'],
        ]);
        $history = $service->change($dealer, DealerTier::findOrFail($data['tier_id']), trim($data['reason']), $data['operation_key'], $request->user());

        return (new DealerTierHistoryResource($history->load(['previousTier', 'newTier', 'actor'])))->response()->setStatusCode(200);
    }

    public function history(DealerAccount $dealer): AnonymousResourceCollection
    {
        return DealerTierHistoryResource::collection($dealer->tierHistories()->with(['previousTier', 'newTier', 'actor:id,name'])->orderByDesc('id')->paginate(20));
    }

    public function overrides(DealerAccount $dealer): JsonResponse
    {
        $items = $dealer->tierOverrides()->with(['tier:id,code,name', 'creator:id,name'])->orderByDesc('id')->paginate(20);

        return response()->json(['data' => $items->items() ? collect($items->items())->map(fn (DealerTierOverride $item): array => $this->overrideData($item))->all() : [],
            'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()]]);
    }

    public function createOverride(Request $request, DealerAccount $dealer, DealerTierService $service): JsonResponse
    {
        $data = $request->validate([
            'tier_id' => ['required', 'integer', 'exists:dealer_tiers,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $item = $service->createOverride($dealer, DealerTier::findOrFail($data['tier_id']), CarbonImmutable::parse($data['starts_at']), isset($data['ends_at']) ? CarbonImmutable::parse($data['ends_at']) : null, trim($data['reason']), $request->user());

        return response()->json(['data' => $this->overrideData($item->load(['tier:id,code,name', 'creator:id,name']))], 201);
    }

    public function cancelOverride(Request $request, DealerAccount $dealer, DealerTierOverride $override, DealerTierService $service): JsonResponse
    {
        $item = $service->cancelOverride($dealer, $override, $request->user());

        return response()->json(['data' => $this->overrideData($item->load(['tier:id,code,name', 'creator:id,name']))]);
    }

    /** @return array<string, mixed> */
    private function overrideData(DealerTierOverride $item): array
    {
        return [
            'id' => $item->id, 'tier' => ['id' => $item->tier->id, 'code' => $item->tier->code, 'name' => $item->tier->name],
            'starts_at' => $item->starts_at->toIso8601String(), 'ends_at' => $item->ends_at?->toIso8601String(),
            'reason' => $item->reason, 'status' => $item->status,
            'created_by' => $item->creator ? ['id' => $item->creator->id, 'name' => $item->creator->name] : null,
            'cancelled_at' => $item->cancelled_at?->toIso8601String(),
        ];
    }
}
