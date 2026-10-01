<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ErpReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'channel' => ['sometimes', Rule::in(['all', 'retail', 'dealer'])],
            'warehouse_id' => ['sometimes', 'integer', 'min:1', 'exists:warehouses,id'],
            'supplier_id' => ['sometimes', 'integer', 'min:1', 'exists:suppliers,id'],
            'dealer_id' => ['sometimes', 'integer', 'min:1', 'exists:dealer_accounts,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }
            $from = $this->input('from', now('Asia/Ho_Chi_Minh')->startOfMonth()->toDateString());
            $to = $this->input('to', now('Asia/Ho_Chi_Minh')->toDateString());
            if ($from > $to) {
                $validator->errors()->add('to', 'Ngày kết thúc phải từ ngày bắt đầu trở đi.');
            }
        }];
    }
}
