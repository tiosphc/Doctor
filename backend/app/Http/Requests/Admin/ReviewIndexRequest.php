<?php

namespace App\Http\Requests\Admin;

use App\Models\Review;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReviewIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'doctor_id' => ['nullable', 'integer', 'exists:doctors,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'status' => ['nullable', 'in:'.implode(',', Review::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
