<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveVoucherRequest;
use App\Http\Resources\VoucherResource;
use App\Models\Voucher;
use App\Services\VoucherService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class VoucherController extends Controller
{
    public function resolve(ResolveVoucherRequest $request, VoucherService $voucherService): VoucherResource
    {
        $voucher = $voucherService->resolveByCode(
            $request->validated('code'),
            $request->user(),
        );

        return new VoucherResource($voucher);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(Voucher::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = $request->user()->vouchers()
            ->when($validated['status'] ?? null, function (Builder $query, string $status): void {
                if ($status === Voucher::STATUS_ACTIVE) {
                    $query->where('status', $status)->where('expires_at', '>=', now());
                } elseif ($status === Voucher::STATUS_EXPIRED) {
                    $query->where(fn (Builder $expired): Builder => $expired
                        ->where('status', Voucher::STATUS_EXPIRED)
                        ->orWhere(fn (Builder $active): Builder => $active->where('status', Voucher::STATUS_ACTIVE)->where('expires_at', '<', now())));
                } else {
                    $query->where('status', $status);
                }
            })
            ->orderByRaw('CASE status WHEN ? THEN 1 WHEN ? THEN 2 WHEN ? THEN 3 ELSE 4 END', [
                Voucher::STATUS_ACTIVE, Voucher::STATUS_USED, Voucher::STATUS_EXPIRED,
            ])->orderByDesc('created_at')->orderByDesc('id');

        return VoucherResource::collection($query->paginate($validated['per_page'] ?? 9)->withQueryString());
    }
}
