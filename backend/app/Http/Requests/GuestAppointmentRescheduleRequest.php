<?php

namespace App\Http\Requests;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class GuestAppointmentRescheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^0[0-9]{9,10}$/'],
            'appointment_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'user_id' => ['prohibited'],
            'doctor_id' => ['prohibited'],
            'service_id' => ['prohibited'],
            'booking_code' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => PhoneNumber::normalize($this->string('phone')->toString())]);
        }
    }
}
