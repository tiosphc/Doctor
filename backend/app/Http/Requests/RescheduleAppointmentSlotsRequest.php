<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleAppointmentSlotsRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'exclude_appointment_id' => ['prohibited'],
            'appointment_id' => ['prohibited'],
            'doctor_id' => ['prohibited'],
            'service_id' => ['prohibited'],
        ];
    }
}
