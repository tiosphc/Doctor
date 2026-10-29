<?php

namespace App\Services;

use App\Models\SalesVoucher;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesVoucherWriteService
{
    public function __construct(private readonly SalesVoucherService $vouchers) {}

    public function suggestCode(): string
    {
        do {
            $code = 'VOUCHER'.Str::upper(Str::random(8));
        } while (SalesVoucher::query()->where('normalized_code', $code)->exists());

        return $code;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): SalesVoucher
    {
        try {
            return DB::transaction(function () use ($data, $actor): SalesVoucher {
                $normalized = $this->vouchers->normalize($data['code']);
                if (SalesVoucher::query()->where('normalized_code', $normalized)->exists()) {
                    throw ValidationException::withMessages(['code' => 'Mã voucher đã tồn tại.']);
                }

                return SalesVoucher::create([...$data, 'normalized_code' => $normalized,
                    'sales_scope' => 'retail', 'created_by_user_id' => $actor->id]);
            }, 3);
        } catch (QueryException $exception) {
            $this->handleDuplicateCode($exception);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(SalesVoucher $voucher, array $data): SalesVoucher
    {
        try {
            return DB::transaction(function () use ($voucher, $data): SalesVoucher {
                $locked = SalesVoucher::query()->lockForUpdate()->findOrFail($voucher->id);
                $normalized = $this->vouchers->normalize($data['code']);
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
            $this->handleDuplicateCode($exception);
            throw $exception;
        }
    }

    private function handleDuplicateCode(QueryException $exception): void
    {
        if (($exception->errorInfo[1] ?? null) === 1062) {
            throw ValidationException::withMessages(['code' => 'Mã voucher đã tồn tại.']);
        }
    }
}
