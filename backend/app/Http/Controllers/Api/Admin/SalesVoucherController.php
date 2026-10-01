<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSalesVoucherRequest;
use App\Models\SalesVoucher;
use App\Services\SalesVoucherService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesVoucherController extends Controller
{
    public function generateCode(): JsonResponse
    {
        do {
            $code = 'VOUCHER'.Str::upper(Str::random(8));
        } while (SalesVoucher::query()->where('normalized_code', $code)->exists());

        return response()->json(['code' => $code]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:active,inactive,upcoming,expired'],
        ]);
        $at = now();
        $vouchers = SalesVoucher::query()
            ->withCount(['redemptions as redeemed_count' => fn ($query) => $query->where('status', 'redeemed')])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query
                ->where(fn ($nested) => $nested->where('normalized_code', 'like', '%'.mb_strtoupper($search).'%')
                    ->orWhere('name', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, function ($query, $status) use ($at): void {
                if ($status === 'inactive') {
                    $query->where('status', 'inactive');
                } elseif ($status === 'upcoming') {
                    $query->where('status', 'active')->where('starts_at', '>', $at);
                } elseif ($status === 'expired') {
                    $query->where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<', $at);
                } else {
                    $query->where('status', 'active')
                        ->where(fn ($nested) => $nested->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
                        ->where(fn ($nested) => $nested->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
                }
            })
            ->latest('id')->paginate(20);

        return response()->json($vouchers);
    }

    public function show(SalesVoucher $voucher): JsonResponse
    {
        return response()->json(['data' => $voucher]);
    }

    public function store(SaveSalesVoucherRequest $request, SalesVoucherService $service): JsonResponse
    {
        try {
            $voucher = DB::transaction(function () use ($request, $service): SalesVoucher {
                $data = $request->validated();
                $normalized = $service->normalize($data['code']);
                if (SalesVoucher::query()->where('normalized_code', $normalized)->exists()) {
                    throw ValidationException::withMessages(['code' => 'Mã voucher đã tồn tại.']);
                }

                return SalesVoucher::create([...$data, 'normalized_code' => $normalized,
                    'sales_scope' => 'retail', 'created_by_user_id' => $request->user()->id]);
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['code' => 'Mã voucher đã tồn tại.']);
            }
            throw $exception;
        }

        return response()->json(['data' => $voucher], 201);
    }

    public function update(SaveSalesVoucherRequest $request, SalesVoucher $voucher, SalesVoucherService $service): JsonResponse
    {
        try {
            $voucher = DB::transaction(function () use ($request, $voucher, $service): SalesVoucher {
                $locked = SalesVoucher::query()->lockForUpdate()->findOrFail($voucher->id);
                $data = $request->validated();
                $normalized = $service->normalize($data['code']);
                if ($normalized !== $locked->normalized_code && $locked->redemptions()->exists()) {
                    throw ValidationException::withMessages(['code' => 'Không thể đổi mã voucher đã được sử dụng.']);
                }
                if (SalesVoucher::query()->where('normalized_code', $normalized)->whereKeyNot($locked->id)->exists()) {
                    throw ValidationException::withMessages(['code' => 'Mã voucher đã tồn tại.']);
                }
                $locked->update([...$data, 'normalized_code' => $normalized]);

                return $locked;
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['code' => 'Mã voucher đã tồn tại.']);
            }
            throw $exception;
        }

        return response()->json(['data' => $voucher]);
    }
}
