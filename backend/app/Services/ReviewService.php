<?php

namespace App\Services;

use App\Exceptions\BusinessConflictException;
use App\Models\Appointment;
use App\Models\Review;
use App\Models\User;
use App\Notifications\ReviewRewardNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ReviewService
{
    public function __construct(private readonly VoucherService $voucherService) {}

    /** @param array{rating: int, comment?: ?string} $data */
    public function create(Appointment $appointment, User $customer, array $data): ReviewCreationResult
    {
        try {
            $result = DB::transaction(function () use ($appointment, $customer, $data): ReviewCreationResult {
                $lockedAppointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

                if ($lockedAppointment->user_id !== $customer->id) {
                    throw new AuthorizationException;
                }

                if ($lockedAppointment->status !== Appointment::STATUS_COMPLETED) {
                    throw new BusinessConflictException('Chỉ lịch hẹn đã hoàn thành mới có thể được đánh giá.');
                }

                if ($lockedAppointment->review()->exists()) {
                    throw new BusinessConflictException('Bạn đã đánh giá lịch hẹn này.');
                }

                $review = Review::create([
                    'appointment_id' => $lockedAppointment->id,
                    'user_id' => $customer->id,
                    'doctor_id' => $lockedAppointment->doctor_id,
                    'service_id' => $lockedAppointment->service_id,
                    'rating' => $data['rating'],
                    'comment' => $data['comment'] ?? null,
                    'status' => Review::STATUS_PUBLISHED,
                ]);
                $voucher = $this->voucherService->issueReviewReward($lockedAppointment);

                return new ReviewCreationResult($review, $voucher);
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000') {
                throw new BusinessConflictException('Bạn đã đánh giá lịch hẹn này.');
            }

            throw $exception;
        }

        if ($result->voucher !== null) {
            $customer->notify(new ReviewRewardNotification($result->voucher));
        }

        return $result;
    }

    /** @param array{rating: int, comment?: ?string} $data */
    public function update(Review $review, User $customer, array $data): Review
    {
        if ($review->user_id !== $customer->id) {
            throw new AuthorizationException;
        }

        $review->update(['rating' => $data['rating'], 'comment' => $data['comment'] ?? null]);

        return $review->refresh();
    }
}
