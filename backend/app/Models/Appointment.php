<?php

namespace App\Models;

use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'guest_name',
    'guest_email',
    'guest_phone',
    'booking_code',
    'doctor_id',
    'service_id',
    'voucher_id',
    'original_price',
    'discount_amount',
    'final_price',
    'appointment_date',
    'start_time',
    'end_time',
    'original_appointment_date',
    'original_start_time',
    'original_end_time',
    'rescheduled_at',
    'reschedule_count',
    'status',
    'reminder_sent_at',
    'note',
])]
class Appointment extends Model
{
    public const RESCHEDULE_LIMIT = 2;

    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_TREATMENT_DONE = 'treatment_done';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_CHECKED_IN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_TREATMENT_DONE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /** Cancelled and completed appointments do not block future availability. */
    public const BLOCKING_STATUSES = [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_CHECKED_IN, self::STATUS_IN_PROGRESS];

    /** @return list<string> */
    public function allowedStatusTransitions(): array
    {
        return match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
            self::STATUS_CONFIRMED => [self::STATUS_CHECKED_IN, self::STATUS_NO_SHOW, self::STATUS_CANCELLED],
            self::STATUS_CHECKED_IN => [self::STATUS_IN_PROGRESS],
            self::STATUS_IN_PROGRESS => [self::STATUS_TREATMENT_DONE],
            self::STATUS_TREATMENT_DONE => [self::STATUS_COMPLETED],
            default => [],
        };
    }

    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    public function isRegisteredCustomer(): bool
    {
        return $this->user_id !== null;
    }

    public function canBeRescheduled(): bool
    {
        return $this->rescheduleBlockReason() === null;
    }

    public function rescheduleBlockReason(): ?string
    {
        if (($this->reschedule_count ?? 0) >= self::RESCHEDULE_LIMIT) {
            return 'Lịch hẹn này đã sử dụng hết số lần đổi lịch.';
        }

        if (! in_array($this->status, [self::STATUS_PENDING, self::STATUS_CONFIRMED], true)) {
            return 'Chỉ lịch hẹn đang chờ xác nhận hoặc đã xác nhận mới có thể đổi lịch.';
        }

        if ($this->isInsideCustomerChangeCutoff()) {
            return 'Lịch hẹn chỉ có thể được thay đổi trước giờ hẹn ít nhất '
                .$this->minimumCustomerChangeNoticeHours().' tiếng.';
        }

        return null;
    }

    public function canBeCancelledByCustomer(): bool
    {
        return $this->cancellationBlockReason() === null;
    }

    public function cancellationBlockReason(): ?string
    {
        if (! in_array($this->status, [self::STATUS_PENDING, self::STATUS_CONFIRMED], true)) {
            return 'Chỉ lịch hẹn đang chờ xác nhận hoặc đã xác nhận mới có thể hủy.';
        }

        if ($this->isInsideCustomerChangeCutoff()) {
            return 'Lịch hẹn chỉ có thể được hủy trước giờ hẹn ít nhất '
                .$this->minimumCustomerChangeNoticeHours().' tiếng.';
        }

        return null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'appointment_date' => 'date',
            'original_appointment_date' => 'date',
            'rescheduled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'reschedule_count' => 'integer',
            'original_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'final_price' => 'decimal:2',
        ];
    }

    private function isInsideCustomerChangeCutoff(): bool
    {
        $startsAt = $this->appointment_date
            ->toImmutable()
            ->setTimeFromTimeString($this->start_time);

        return now()->gt($startsAt->subHours($this->minimumCustomerChangeNoticeHours()));
    }

    private function minimumCustomerChangeNoticeHours(): int
    {
        return max(0, (int) config('booking.customer_changes.minimum_notice_hours', 2));
    }
}
