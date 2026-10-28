<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use App\Support\Sku;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveBladeProductRequest extends SaveProductRequest
{
    public function rules(): array
    {
        $rules = [...parent::rules(),
            'base_sku' => $this->isMethod('post') ? ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('products', 'base_sku')] : ['prohibited'],
            'variants' => $this->isMethod('post') ? ['required', 'array', 'min:1', 'max:100'] : ['prohibited'],
            'variants.*.sku' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', 'distinct', Rule::unique('product_variants', 'sku')],
            'variants.*.variant_name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/u'],
            'variants.*.unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('status', 'active')],
            'variants.*.sellable_retail' => ['required', 'boolean'],
            'variants.*.sellable_dealer' => ['required', 'boolean'],
            'images' => ['sometimes', 'array'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
        $product = $this->route('product');
        if ($product instanceof Product && ! $this->isMethod('post')) {
            $rules['product_category_id'] = ['sometimes', 'integer', Rule::exists('product_categories', 'id')->where(fn ($query) => $query->where('status', 'active')->orWhere('id', $product->product_category_id))];
            $rules['brand_id'] = ['nullable', 'integer', Rule::exists('brands', 'id')->where(fn ($query) => $query->where('status', 'active')->orWhere('id', $product->brand_id))];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $baseSku = $this->input('base_sku');
        if (is_string($baseSku)) {
            $baseSku = Sku::normalize($baseSku);
            $this->merge(['base_sku' => $baseSku]);
        }
        $variants = $this->input('variants');
        if (! is_array($variants)) {
            return;
        }
        foreach ($variants as $index => $variant) {
            if (is_array($variant) && is_string($variant['variant_name'] ?? null)) {
                $variants[$index]['variant_name'] = trim($variant['variant_name']);
                $variants[$index]['sku'] = Sku::fromVariantName((string) $baseSku, $variants[$index]['variant_name']);
            }
        }
        $this->merge(['variants' => $variants, 'default_unit_id' => $variants[array_key_first($variants)]['unit_id'] ?? null]);
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->after(function (Validator $validator): void {
            $baseSku = $this->input('base_sku');
            if (is_string($baseSku) && $baseSku !== '' && DB::table('product_variants')->where('sku', $baseSku)->exists()) {
                $validator->errors()->add('base_sku', 'SKU gốc đã tồn tại.');
            }
            $variants = $this->input('variants');
            if (! is_array($variants)) {
                return;
            }
            foreach ($variants as $index => $variant) {
                if (is_array($variant) && ! ($variant['sellable_retail'] ?? false) && ! ($variant['sellable_dealer'] ?? false) && ! $this->boolean('gift_only')) {
                    $validator->errors()->add("variants.$index.sellable_retail", 'Chọn ít nhất một kênh bán cho biến thể.');
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Tên sản phẩm là bắt buộc.',
            'base_sku.required' => 'SKU gốc là bắt buộc.',
            'base_sku.unique' => 'SKU gốc đã tồn tại.',
            'slug.required' => 'Slug là bắt buộc.',
            'product_category_id.required' => 'Vui lòng chọn danh mục.',
            'variants.required' => 'Sản phẩm phải có ít nhất một biến thể.',
            'variants.min' => 'Sản phẩm phải có ít nhất một biến thể.',
            'variants.*.variant_name.required' => 'Tên biến thể là bắt buộc.',
            'variants.*.sku.required' => 'Mã SKU là bắt buộc.',
            'variants.*.sku.unique' => 'Mã SKU đã tồn tại.',
            'variants.*.sku.distinct' => 'Mã SKU bị trùng trong biểu mẫu.',
            'variants.*.unit_id.required' => 'Vui lòng chọn đơn vị.',
            'images.*.image' => 'Tệp tải lên phải là hình ảnh.',
            'images.*.mimes' => 'Ảnh phải là JPG, PNG hoặc WebP.',
            'images.*.max' => 'Mỗi ảnh tối đa 5 MB.',
        ];
    }
}
