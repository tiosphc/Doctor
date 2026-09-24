<?php

namespace App\Http\Requests\Admin;

use App\Support\Sku;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveProductWizardDraftRequest extends FormRequest
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
        return [
            'wizard_key' => ['required', 'uuid'],
            'data' => ['required', 'array'],
            'data.name' => ['nullable', 'string', 'max:255'],
            'data.sku' => ['nullable', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'],
            'data.product_category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where('status', 'active')],
            'data.brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('status', 'active')],
            'data.unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('status', 'active')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = $this->input('data');
        if (is_array($data) && isset($data['sku']) && is_string($data['sku'])) {
            $data['sku'] = Sku::normalize($data['sku']);
            $this->merge(['data' => $data]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (strlen(json_encode($this->input('data', [])) ?: '') > 100000) {
                $validator->errors()->add('data', 'Bản nháp vượt quá giới hạn dữ liệu.');
            }
        });
    }
}
