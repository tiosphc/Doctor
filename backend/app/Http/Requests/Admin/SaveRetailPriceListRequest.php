<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRetailPriceListRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('price_lists', 'code')->ignore($this->route('priceList'))],
            'name' => [$required, 'string', 'max:255'],
            'pricing_context' => ['sometimes', Rule::in(['retail'])],
            'scope_type' => ['sometimes', Rule::in(['all'])],
            'currency' => ['sometimes', Rule::in(['VND'])],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }
}
