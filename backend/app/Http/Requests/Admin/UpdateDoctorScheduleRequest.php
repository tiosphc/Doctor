<?php

namespace App\Http\Requests\Admin;

use App\Models\DoctorSchedule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDoctorScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $schedule = $this->route('schedule');

        if ($schedule instanceof DoctorSchedule) {
            $this->merge([
                'day_of_week' => $this->input('day_of_week', $schedule->day_of_week),
                'start_time' => $this->input('start_time', substr($schedule->start_time, 0, 5)),
                'end_time' => $this->input('end_time', substr($schedule->end_time, 0, 5)),
            ]);
        }
    }
}
