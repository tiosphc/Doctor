<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreDoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'specialty' => ['required', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:doctors,email'],
            'avatar' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'status' => ['prohibited'],
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
