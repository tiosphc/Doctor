<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveProductRequest extends FormRequest
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
            'name' => [$required, 'string', 'max:255'],
            'slug' => [$required, 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($this->route('product'))],
            'description' => ['nullable', 'string', 'max:20000'],
            'product_category_id' => [$required, 'integer', Rule::exists('product_categories', 'id')->where('status', 'active')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('status', 'active')],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'track_inventory' => ['sometimes', 'boolean'],
            'can_be_gift' => ['sometimes', 'boolean'],
            'gift_only' => ['sometimes', 'boolean'],
            'track_batch' => ['sometimes', 'boolean'],
            'track_expiry' => ['sometimes', 'boolean'],
            'default_low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'default_unit_id' => $this->isMethod('post') ? ['required', 'integer', Rule::exists('units', 'id')->where('status', 'active')] : ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $product = $this->route('product');
            $giftOnly = $this->has('gift_only') ? $this->boolean('gift_only') : ($product?->gift_only ?? false);
            $canBeGift = $this->has('can_be_gift') ? $this->boolean('can_be_gift') : ($product?->can_be_gift ?? false);
            $trackInventory = $this->has('track_inventory') ? $this->boolean('track_inventory') : ($product?->track_inventory ?? $this->isMethod('post'));
            if ($giftOnly && ! $canBeGift) {
                $validator->errors()->add('can_be_gift', 'Gift-only Product must be enabled for gifts.');
            }
            if ($giftOnly && ! $trackInventory) {
                $validator->errors()->add('track_inventory', 'Gift-only Product must track inventory.');
            }
        });
    }
}
