<?php

namespace App\Http\Requests\Admin;

use App\Models\Doctor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReplaceDoctorSchedulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'schedules' => ['present', 'array', 'max:70'],
            'schedules.*.id' => ['nullable', 'integer', 'distinct:strict'],
            'schedules.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'schedules.*.start_time' => ['required', 'date_format:H:i'],
            'schedules.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $schedules = collect($this->input('schedules', []));
            $doctor = $this->route('doctor');
            $scheduleIds = $schedules->pluck('id')->filter()->values();

            if ($doctor instanceof Doctor && $scheduleIds->isNotEmpty()) {
                $matchingScheduleCount = $doctor->schedules()->whereKey($scheduleIds)->count();

                if ($matchingScheduleCount !== $scheduleIds->count()) {
                    $validator->errors()->add('schedules', 'Một hoặc nhiều ca làm việc không thuộc bác sĩ này.');

                    return;
                }
            }

            $seen = [];
            foreach ($schedules as $index => $schedule) {
                if ($schedule['start_time'] >= $schedule['end_time']) {
                    $validator->errors()->add("schedules.{$index}.end_time", 'Giờ kết thúc phải sau giờ bắt đầu.');
                }

                $key = implode('|', [
                    $schedule['day_of_week'],
                    $schedule['start_time'],
                    $schedule['end_time'],
                ]);

                if (isset($seen[$key])) {
                    $validator->errors()->add("schedules.{$index}.start_time", 'Ca làm việc bị trùng lặp.');
                }

                $seen[$key] = true;
            }

            foreach ($schedules->groupBy('day_of_week') as $daySchedules) {
                $sorted = $daySchedules->sortBy('start_time')->values();

                for ($index = 1; $index < $sorted->count(); $index++) {
                    if ($sorted[$index]['start_time'] < $sorted[$index - 1]['end_time']) {
                        $validator->errors()->add('schedules', 'Các ca làm việc trong cùng ngày không được chồng lấn.');

                        return;
                    }
                }
            }
        }];
    }
}
