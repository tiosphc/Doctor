<?php

namespace App\Http\Requests\Admin;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentIndexRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(Appointment::STATUSES)],
            'quick_filter' => ['nullable', Rule::in(['all', 'new', 'upcoming', 'checked_in', 'in_progress', 'treatment_done', 'completed', 'cancelled', 'no_show'])],
            'sort' => ['nullable', Rule::in(['nearest', 'newest', 'farthest', 'oldest'])],
            'doctor_id' => ['nullable', 'integer', 'exists:doctors,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
