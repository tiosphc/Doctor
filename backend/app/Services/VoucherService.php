<?php

namespace App\Services;

use App\Exceptions\BusinessConflictException;
use App\Models\Appointment;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class VoucherService
{
    public function resolveByCode(string $code, User $customer): Voucher
    {
        $voucher = Voucher::query()
            ->where('code', Str::upper(trim($code)))
            ->where(function (Builder $query) use ($customer): void {
                $query->where('user_id', $customer->id)
                    ->orWhere(function (Builder $general): void {
                        $general->where('source', Voucher::SOURCE_ADMIN)
                            ->whereNull('user_id');
                    });
            })
            ->first();

        if ($voucher === null) {
            throw ValidationException::withMessages([
                'code' => 'Mã voucher không hợp lệ hoặc không thuộc tài khoản của bạn.',
            ]);
        }

        if ($voucher->isExpired()) {
            throw ValidationException::withMessages([
                'code' => 'Mã voucher này đã hết hạn.',
            ]);
        }

        if ($voucher->status === Voucher::STATUS_USED || $voucher->used_at !== null) {
            throw ValidationException::withMessages([
                'code' => 'Mã voucher này đã được sử dụng.',
            ]);
        }

        if ($voucher->status !== Voucher::STATUS_ACTIVE || $voucher->type !== Voucher::TYPE_PERCENTAGE) {
            throw ValidationException::withMessages([
                'code' => 'Mã voucher không hợp lệ.',
            ]);
        }

        return $voucher;
    }

    public function generateAdminCode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = 'JUN-'.Str::upper(Str::random(6));

            if (! Voucher::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Unable to generate a unique Admin voucher code.');
    }

    public function createAdminVoucher(string $code, int|float $value, string $expiresAt): Voucher
    {
        try {
            return Voucher::create([
                'code' => Str::upper(trim($code)),
                'user_id' => null,
                'type' => Voucher::TYPE_PERCENTAGE,
                'value' => $value,
                'source' => Voucher::SOURCE_ADMIN,
                'source_id' => null,
                'status' => Voucher::STATUS_ACTIVE,
                'expires_at' => CarbonImmutable::parse($expiresAt)->endOfDay(),
            ]);
        } catch (QueryException $exception) {
            if ($this->isCodeCollision($exception)) {
                throw ValidationException::withMessages([
                    'code' => 'Mã voucher đã tồn tại.',
                ]);
            }

            throw $exception;
        }
    }

    public function issueReviewReward(Appointment $appointment): ?Voucher
    {
        if (! (bool) config('rewards.review.enabled')) {
            return null;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Voucher::create([
                    'code' => $this->generateCode(),
                    'user_id' => $appointment->user_id,
                    'type' => Voucher::TYPE_PERCENTAGE,
                    'value' => max(1, min(100, (int) config('rewards.review.percent'))),
                    'source' => Voucher::SOURCE_REVIEW_REWARD,
                    'source_id' => $appointment->id,
                    'status' => Voucher::STATUS_ACTIVE,
                    'expires_at' => now()->addDays(max(1, (int) config('rewards.review.expiry_days'))),
                ]);
            } catch (QueryException $exception) {
                if ($this->isSourceCollision($exception)) {
                    throw new BusinessConflictException('Phần thưởng đánh giá đã được cấp cho lịch hẹn này.');
                }

                if (! $this->isCodeCollision($exception)) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Unable to generate a unique voucher code.');
    }

    public function issueLoyaltyReward(
        User $customer,
        int $milestone,
        int $percent,
        int $expiryDays,
    ): ?Voucher {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Voucher::create([
                    'code' => $this->generateCode('LOY'),
                    'user_id' => $customer->id,
                    'type' => Voucher::TYPE_PERCENTAGE,
                    'value' => max(1, min(100, $percent)),
                    'source' => Voucher::SOURCE_LOYALTY_MILESTONE,
                    'source_id' => $milestone,
                    'status' => Voucher::STATUS_ACTIVE,
                    'expires_at' => now()->addDays(max(1, $expiryDays)),
                ]);
            } catch (QueryException $exception) {
                if ($this->isSourceCollision($exception)) {
                    return null;
                }

                if (! $this->isCodeCollision($exception)) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Unable to generate a unique voucher code.');
    }

    public function lockForRedemption(int $voucherId, User $customer): Voucher
    {
        $voucher = Voucher::query()->whereKey($voucherId)->lockForUpdate()->first();

        if ($voucher === null || ($voucher->user_id !== $customer->id && ! $voucher->isGeneralAdminVoucher())) {
            throw new AuthorizationException('Voucher không hợp lệ.');
        }

        if ($voucher->isExpired()) {
            if ($voucher->status === Voucher::STATUS_ACTIVE) {
                $voucher->update(['status' => Voucher::STATUS_EXPIRED]);
            }
            throw new BusinessConflictException('Voucher này đã hết hạn.');
        }

        if ($voucher->status === Voucher::STATUS_USED || $voucher->used_at !== null) {
            throw new BusinessConflictException('Voucher này đã được sử dụng.');
        }

        if ($voucher->status !== Voucher::STATUS_ACTIVE || $voucher->type !== Voucher::TYPE_PERCENTAGE) {
            throw new BusinessConflictException('Voucher không hợp lệ.');
        }

        return $voucher;
    }

    public function consume(Voucher $voucher): void
    {
        $voucher->update(['status' => Voucher::STATUS_USED, 'used_at' => now()]);
    }

    public function restoreForCancelledAppointment(Appointment $appointment): void
    {
        if ($appointment->voucher_id === null) {
            return;
        }

        $voucher = Voucher::query()->whereKey($appointment->voucher_id)->lockForUpdate()->first();

        if ($voucher === null || $voucher->status !== Voucher::STATUS_USED) {
            return;
        }

        $voucher->update([
            'status' => $voucher->isExpired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE,
            'used_at' => null,
        ]);
    }

    private function generateCode(string $prefix = 'RVW'): string
    {
        return $prefix.'-'.Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4));
    }

    private function isCodeCollision(QueryException $exception): bool
    {
        return $exception->getCode() === '23000' && str_contains($exception->getMessage(), 'vouchers_code_unique');
    }

    private function isSourceCollision(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            && (str_contains($exception->getMessage(), 'vouchers_source_source_id_unique')
                || str_contains($exception->getMessage(), 'vouchers_user_source_source_id_unique'));
    }
}
