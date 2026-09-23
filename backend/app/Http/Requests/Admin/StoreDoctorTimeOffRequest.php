<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDoctorTimeOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->validateTimeRange($validator);
        }];
    }

    protected function validateTimeRange(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['start_time', 'end_time'])) {
            return;
        }

        $startTime = $this->input('start_time');
        $endTime = $this->input('end_time');

        if (($startTime === null) !== ($endTime === null)) {
            $validator->errors()->add('time_range', 'Start time and end time must both be provided or both be null.');

            return;
        }

        if ($startTime !== null && $endTime <= $startTime) {
            $validator->errors()->add('end_time', 'The end time must be after the start time.');
        }
    }
}
