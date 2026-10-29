<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment
            && ($this->user()?->can('reschedule', $appointment) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'appointment_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'user_id' => ['prohibited'],
            'doctor_id' => ['prohibited'],
            'service_id' => ['prohibited'],
            'status' => ['prohibited'],
            'end_time' => ['prohibited'],
            'guest_name' => ['prohibited'],
            'guest_email' => ['prohibited'],
            'guest_phone' => ['prohibited'],
            'booking_code' => ['prohibited'],
            'note' => ['prohibited'],
            'appointment_id' => ['prohibited'],
            'exclude_appointment_id' => ['prohibited'],
        ];
    }
}
