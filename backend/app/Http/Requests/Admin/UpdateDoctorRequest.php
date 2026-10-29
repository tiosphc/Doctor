<?php

namespace App\Http\Requests\Admin;

use App\Models\Doctor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateDoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'specialty' => ['sometimes', 'required', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('doctor')->user_id),
                Rule::unique('doctors', 'email')->ignore($this->route('doctor')),
            ],
            'avatar' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'status' => ['sometimes', 'required', Rule::in(Doctor::STATUSES)],
            'user_id' => ['prohibited'],
            'role' => ['prohibited'],
            'password' => ['prohibited'],
            'must_change_password' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'Email này đã được sử dụng bởi một tài khoản khác.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
        }
    }
}
