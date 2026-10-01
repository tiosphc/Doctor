<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class ReviewDealerQuickOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:product_variant_id,quantity'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'promotion_code' => ['prohibited'], 'voucher_code' => ['prohibited'],
            'items.*.sku' => ['prohibited'], 'items.*.unit_price' => ['prohibited'],
            'items.*.minimum_quantity' => ['prohibited'], 'items.*.price_list_id' => ['prohibited'],
            'buyer_user_id' => ['prohibited'], 'dealer_account_id' => ['prohibited'],
            'tier_id' => ['prohibited'], 'warehouse_id' => ['prohibited'],
            'shipping_address_id' => ['required_without:shipping_province_code', 'integer', 'min:1'],
            'shipping_province_code' => ['required_without:shipping_address_id', 'string', 'max:10'],
            'shipping_ward_code' => ['required_without:shipping_address_id', 'string', 'max:10'],
            'recipient_name' => ['required_without:shipping_address_id', 'string', 'max:255'],
            'recipient_phone' => ['required_without:shipping_address_id', 'string', 'max:50', 'regex:/^(?=.*[0-9])[+0-9().\-\s]+$/'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'shipping_address_line1' => ['required_without:shipping_address_id', 'string', 'max:255'],
            'shipping_address_line2' => ['nullable', 'string', 'max:255'],
            'shipping_postal_code' => ['nullable', 'string', 'max:30'],
            'shipping_district' => ['nullable', 'string', 'max:255'],
            'delivery_note' => ['nullable', 'string', 'max:2000'],
            'currency' => ['prohibited'], 'subtotal' => ['prohibited'],
            'grand_total' => ['prohibited'], 'sales_channel' => ['prohibited'],
            'order_source' => ['prohibited'], 'payment_status' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('voucher_code') || $this->exists('promotion_code')) {
            throw new HttpResponseException(response()->json([
                'code' => 'VOUCHER_NOT_AVAILABLE_FOR_DEALER',
                'message' => 'Voucher hiện không áp dụng cho đơn hàng đại lý.',
            ], 409));
        }
        $items = $this->input('items');
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $index => $item) {
            if (is_array($item) && is_numeric($item['quantity'] ?? null)) {
                $items[$index]['quantity'] = (string) $item['quantity'];
            }
        }
        $this->merge(['items' => $items]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $seen = [];
            foreach (is_array($this->input('items')) ? $this->input('items') : [] as $index => $item) {
                if (! is_array($item) || ! is_numeric($item['product_variant_id'] ?? null)) {
                    continue;
                }
                $id = (int) $item['product_variant_id'];
                if (isset($seen[$id])) {
                    $validator->errors()->add("items.$index.product_variant_id", 'Duplicate SKU lines are not allowed.');
                }
                $seen[$id] = true;
            }
        });
    }
}
