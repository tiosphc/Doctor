<?php

namespace App\Http\Requests;

use App\Models\Review;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $review = $this->route('review');

        return $this->user() instanceof User
            && $this->user()->isCustomer()
            && $review instanceof Review
            && $review->user_id === $this->user()->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'appointment_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'doctor_id' => ['prohibited'],
            'service_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
