<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuditLogIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'role' => ['nullable', Rule::in([...User::ROLES, 'guest'])],
            'module' => ['nullable', Rule::in(AuditLogger::MODULES)],
            'action' => ['nullable', Rule::in(AuditLogger::ACTIONS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
        ];
    }
}
