<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RetailCheckoutRequest extends FormRequest
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
            'checkout_operation_key' => ['required', 'uuid'],
            'checkout_review_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_phone' => ['required', 'string', 'max:50'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'shipping_address_line1' => ['required', 'string', 'max:255'],
            'shipping_address_line2' => ['nullable', 'string', 'max:255'],
            'shipping_city' => ['required', 'string', 'max:255'],
            'shipping_district' => ['required', 'string', 'max:255'],
            'shipping_province' => ['required', 'string', 'max:255'],
            'shipping_country' => ['required', 'string', 'max:255'],
            'shipping_postal_code' => ['nullable', 'string', 'max:30'],
            'delivery_note' => ['nullable', 'string', 'max:2000'],
            'payment_method' => ['required', 'in:cod,bank_transfer'],
            'buyer_user_id' => ['prohibited'], 'warehouse_id' => ['prohibited'],
            'sales_channel' => ['prohibited'], 'order_source' => ['prohibited'],
            'currency' => ['prohibited'], 'unit_price' => ['prohibited'],
            'subtotal' => ['prohibited'], 'grand_total' => ['prohibited'],
            'order_status' => ['prohibited'], 'reserved_quantity' => ['prohibited'],
        ];
    }
}
