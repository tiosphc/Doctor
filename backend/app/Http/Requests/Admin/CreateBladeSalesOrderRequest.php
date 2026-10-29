<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class CreateBladeSalesOrderRequest extends CreateSalesOrderRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'sales_channel' => ['required', Rule::in(['retail'])],
            'buyer_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'customer')],
            'confirm' => ['prohibited'],
            'confirm_operation_key' => ['prohibited'],
        ];
    }
}
