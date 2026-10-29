<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveProductPricingRequest extends FormRequest
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
        $retail = $this->boolean('sellable_retail');
        $dealer = $this->boolean('sellable_dealer');

        return [
            'sellable_retail' => ['required', 'boolean'],
            'sellable_dealer' => ['required', 'boolean'],
            'retail_price' => [$retail ? 'required' : 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'variant_retail_prices' => ['sometimes', 'array', 'max:100'],
            'variant_retail_prices.*.sku' => ['required', 'string', 'max:100'],
            'variant_retail_prices.*.unit_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'dealer_rules' => [$dealer ? 'required' : 'sometimes', 'array', 'max:100'],
            'dealer_rules.*.tier_id' => ['required', 'integer', Rule::exists('dealer_tiers', 'id')->where('status', 'active')],
            'dealer_rules.*.sku' => ['required', 'string', 'max:100'],
            'dealer_rules.*.min_quantity' => ['required', 'integer', 'min:1'],
            'dealer_rules.*.unit_price' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('sellable_retail') && ! $this->boolean('sellable_dealer')) {
                $validator->errors()->add('channels', 'Chọn ít nhất một kênh bán.');
            }
            $product = $this->route('product');
            $skus = $product instanceof Product ? $product->variants()->pluck('sku')->all() : [];
            $seen = [];
            $seenRetail = [];
            $retailRows = $this->input('variant_retail_prices', []);
            foreach (is_array($retailRows) ? $retailRows : [] as $index => $row) {
                if (! is_array($row) || ! in_array($row['sku'] ?? null, $skus, true)) {
                    $validator->errors()->add("variant_retail_prices.$index.sku", 'SKU không thuộc sản phẩm.');
                } elseif (isset($seenRetail[$row['sku']])) {
                    $validator->errors()->add("variant_retail_prices.$index.sku", 'Giá Retail của biến thể này đã tồn tại.');
                } else {
                    $seenRetail[$row['sku']] = true;
                }
            }
            $dealerRows = $this->input('dealer_rules', []);
            foreach (is_array($dealerRows) ? $dealerRows : [] as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $sku = $row['sku'] ?? null;
                if (! in_array($sku, $skus, true)) {
                    $validator->errors()->add("dealer_rules.$index.sku", 'SKU không thuộc sản phẩm.');
                }
                $key = ($row['tier_id'] ?? '').':'.($sku ?? '');
                if (isset($seen[$key])) {
                    $validator->errors()->add("dealer_rules.$index.sku", 'Giá của Tier cho biến thể này đã tồn tại.');
                }
                $seen[$key] = true;
            }
        });
    }
}
