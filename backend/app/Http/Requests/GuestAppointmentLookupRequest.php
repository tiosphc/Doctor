<?php

namespace App\Http\Requests;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class GuestAppointmentLookupRequest extends FormRequest
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
            'booking_code' => ['required', 'string', 'max:32'],
            'phone' => ['required', 'string', 'regex:/^0[0-9]{9,10}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'booking_code' => Str::upper(trim($this->string('booking_code')->toString())),
            'phone' => PhoneNumber::normalize($this->string('phone')->toString()),
        ]);
    }
}
