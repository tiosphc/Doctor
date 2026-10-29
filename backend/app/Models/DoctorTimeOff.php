<?php

namespace App\Models;

use Database\Factories\DoctorTimeOffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['doctor_id', 'date', 'start_time', 'end_time', 'reason'])]
class DoctorTimeOff extends Model
{
    /** @use HasFactory<DoctorTimeOffFactory> */
    use HasFactory;

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
