<?php

namespace App\Models;

use Database\Factories\DoctorScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['doctor_id', 'day_of_week', 'start_time', 'end_time'])]
class DoctorSchedule extends Model
{
    /** @use HasFactory<DoctorScheduleFactory> */
    use HasFactory;

    public const DAYS_OF_WEEK = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function dayName(): string
    {
        return self::DAYS_OF_WEEK[$this->day_of_week];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }
}
