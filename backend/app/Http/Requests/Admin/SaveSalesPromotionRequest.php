<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveSalesPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isGift = $this->input('discount_type') === 'buy_a_get_b';

        return [
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed_amount', 'buy_a_get_b'])],
            'discount_value' => $isGift ? ['nullable', 'numeric', 'in:0'] : ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999999.99',
                Rule::when($this->input('discount_type') === 'percentage', ['lte:100'])],
            'max_discount_amount' => $isGift ? ['prohibited'] : ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'sales_scope' => ['required', Rule::in(['retail', 'dealer', 'both'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'total_usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_buyer_usage_limit' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'product_ids' => [$isGift ? 'prohibited' : 'sometimes', 'array'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'category_ids' => [$isGift ? 'prohibited' : 'sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:product_categories,id'],
            'dealer_tier_ids' => ['sometimes', 'array'],
            'dealer_tier_ids.*' => ['integer', 'distinct', Rule::exists('dealer_tiers', 'id')->where('status', 'active')],
            'gift_rule' => [$isGift ? 'required' : 'prohibited', 'array'],
            'gift_rule.buy_product_id' => [$isGift ? 'required' : 'prohibited', 'integer', 'exists:products,id'],
            'gift_rule.buy_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'gift_rule.minimum_buy_quantity' => [$isGift ? 'required' : 'prohibited', 'integer', 'min:1'],
            'gift_rule.gift_product_id' => [$isGift ? 'required' : 'prohibited', 'integer', 'exists:products,id'],
            'gift_rule.gift_variant_id' => [$isGift ? 'required' : 'prohibited', 'integer', 'exists:product_variants,id'],
            'gift_rule.gift_quantity' => [$isGift ? 'required' : 'prohibited', 'integer', 'min:1'],
            'gift_rule.repeat_per_multiple' => [$isGift ? 'required' : 'prohibited', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('discount_type') !== 'buy_a_get_b') {
                return;
            }
            $rule = $this->input('gift_rule', []);
            if (! is_array($rule)) {
                return;
            }
            $buy = DB::table('products')->where('id', $rule['buy_product_id'] ?? 0)->first();
            $gift = DB::table('products')->where('id', $rule['gift_product_id'] ?? 0)->first();
            if ($buy === null || $buy->status !== 'active' || $buy->gift_only) {
                $validator->errors()->add('gift_rule.buy_product_id', 'Buy Product must be active and normally purchasable.');
            }
            if ($gift === null || $gift->status !== 'active'
                || (! $gift->can_be_gift && $gift->id !== ($buy->id ?? null))) {
                $validator->errors()->add('gift_rule.gift_product_id', 'Gift Product must be active and enabled for gifts, or be the Buy Product.');
            }
            $buyVariantId = $rule['buy_variant_id'] ?? null;
            if ($buyVariantId !== null) {
                $variant = DB::table('product_variants')->where('id', $buyVariantId)->first();
                if ($variant === null || $variant->product_id !== ($buy->id ?? null) || $variant->status !== 'active'
                    || ! $variant->track_inventory || (in_array($this->input('sales_scope'), ['retail', 'both'], true) && ! $variant->sellable_retail)
                    || (in_array($this->input('sales_scope'), ['dealer', 'both'], true) && ! $variant->sellable_dealer)) {
                    $validator->errors()->add('gift_rule.buy_variant_id', 'Buy SKU is unavailable for this channel.');
                } elseif (strlen(explode('.', (string) ($rule['minimum_buy_quantity'] ?? ''))[1] ?? '')
                    > DB::table('units')->where('id', $variant->unit_id)->value('decimal_precision')) {
                    $validator->errors()->add('gift_rule.minimum_buy_quantity', 'Quantity exceeds Unit precision.');
                }
            } elseif ($buy !== null) {
                $variants = DB::table('product_variants')->where('product_id', $buy->id)
                    ->where('status', 'active')->where('track_inventory', true);
                $scope = $this->input('sales_scope');
                if ((in_array($scope, ['retail', 'both'], true) && ! (clone $variants)->where('sellable_retail', true)->exists())
                    || (in_array($scope, ['dealer', 'both'], true) && ! (clone $variants)->where('sellable_dealer', true)->exists())) {
                    $validator->errors()->add('gift_rule.buy_product_id', 'Buy Product has no eligible SKU.');
                }
            }
            $giftVariant = DB::table('product_variants')->where('id', $rule['gift_variant_id'] ?? 0)->first();
            if ($giftVariant === null || $giftVariant->product_id !== ($gift->id ?? null)
                || $giftVariant->status !== 'active' || ! $giftVariant->track_inventory) {
                $validator->errors()->add('gift_rule.gift_variant_id', 'Gift SKU must be active and inventory tracked.');
            } elseif (strlen(explode('.', (string) ($rule['gift_quantity'] ?? ''))[1] ?? '')
                > DB::table('units')->where('id', $giftVariant->unit_id)->value('decimal_precision')) {
                $validator->errors()->add('gift_rule.gift_quantity', 'Quantity exceeds Unit precision.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('sales_scope') === 'retail') {
            $this->merge(['dealer_tier_ids' => []]);
        }
        if ($this->has('code')) {
            $this->merge(['code' => trim((string) $this->input('code'))]);
        }
    }
}
