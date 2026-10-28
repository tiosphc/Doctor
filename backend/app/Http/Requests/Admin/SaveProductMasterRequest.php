<?php

namespace App\Http\Requests\Admin;

use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\Unit;
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
        $routeName = (string) $this->route()?->getName();
        $kind = $this->route('kind')
            ?? (str_starts_with($routeName, 'admin.web.categories.') ? 'categories' : null)
            ?? (str_starts_with($routeName, 'admin.web.brands.') ? 'brands' : null)
            ?? (str_starts_with($routeName, 'admin.web.units.') ? 'units' : null);
        $table = ['categories' => 'product_categories', 'brands' => 'brands', 'units' => 'units'][$kind] ?? null;
        abort_unless($table, 404);
        $id = $this->route('id')
            ?? ($this->route('category') instanceof ProductCategory ? $this->route('category')->getKey() : null)
            ?? ($this->route('brand') instanceof Brand ? $this->route('brand')->getKey() : null)
            ?? ($this->route('unit') instanceof Unit ? $this->route('unit')->getKey() : null);
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
