<?php

namespace App\Http\Requests\Admin;

use App\Services\AdminDashboardService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['period' => ['nullable', Rule::in(AdminDashboardService::PERIODS)]];
    }
}
