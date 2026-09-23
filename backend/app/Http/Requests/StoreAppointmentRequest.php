<?php

namespace App\Http\Requests;

use App\Models\Service;
use App\Support\PhoneNumber;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if ($this->user() !== null) {
            return $this->user()->isCustomer();
        }

        return $this->hasAny(['guest_name', 'guest_email', 'guest_phone', 'verification_token']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $guestRule = fn (array $rules): array => $this->user() === null ? ['required', ...$rules] : ['prohibited'];

        return [
            'doctor_id' => ['required', 'integer', 'exists:doctors,id'],
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where('status', Service::STATUS_ACTIVE),
            ],
            'appointment_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:5000'],
            'voucher_id' => $this->user() === null
                ? ['prohibited']
                : ['nullable', 'integer', 'exists:vouchers,id'],
            'voucher_ids' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'discount_percent' => ['prohibited'],
            'original_price' => ['prohibited'],
            'final_price' => ['prohibited'],
            'guest_name' => $guestRule(['string', 'max:255']),
            'guest_email' => $guestRule(['email', 'max:255']),
            'guest_phone' => $guestRule(['string', 'regex:/^0[0-9]{9,10}$/']),
            'verification_token' => $guestRule(['string', 'max:128']),
            'user_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'customer_name_snapshot' => ['prohibited'],
            'customer_email_snapshot' => ['prohibited'],
            'customer_phone_snapshot' => ['prohibited'],
            'end_time' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('guest_name')) {
            $normalized['guest_name'] = trim($this->string('guest_name')->toString());
        }

        if ($this->has('guest_email')) {
            $normalized['guest_email'] = Str::lower(trim($this->string('guest_email')->toString()));
        }

        if ($this->has('guest_phone')) {
            $normalized['guest_phone'] = PhoneNumber::normalize($this->string('guest_phone')->toString());
        }

        if ($this->has('verification_token')) {
            $normalized['verification_token'] = trim($this->string('verification_token')->toString());
        }

        $this->merge($normalized);
    }

    protected function failedAuthorization(): never
    {
        if ($this->user() === null) {
            throw new AuthenticationException;
        }

        parent::failedAuthorization();
    }
}
