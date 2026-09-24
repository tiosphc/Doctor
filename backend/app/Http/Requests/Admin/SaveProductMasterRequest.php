<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductMasterRequest extends FormRequest
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
        $kind = $this->route('kind');
        $table = ['categories' => 'product_categories', 'brands' => 'brands', 'units' => 'units'][$kind] ?? null;
        abort_unless($table, 404);
        $id = $this->route('id');
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', Rule::unique($table, 'code')->ignore($id)],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'parent_id' => $kind === 'categories' ? ['nullable', 'integer', Rule::exists($table, 'id')] : ['prohibited'],
            'sort_order' => $kind === 'categories' ? ['sometimes', 'integer', 'min:0'] : ['prohibited'],
            'symbol' => $kind === 'units' ? [$required, 'string', 'max:30'] : ['prohibited'],
            'decimal_precision' => $kind === 'units' ? [$required, 'integer', 'min:0', 'max:3'] : ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }
}
