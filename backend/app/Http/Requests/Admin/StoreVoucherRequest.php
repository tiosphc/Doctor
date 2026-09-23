<?php

namespace App\Http\Requests\Admin;

use App\Models\Voucher;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVoucherRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'min:4',
                'max:32',
                'regex:/^[A-Z0-9][A-Z0-9-]*$/',
                Rule::unique(Voucher::class, 'code'),
            ],
            'value' => ['required', 'numeric', Rule::in([5, 10, 15, 20, 25, 30, 50])],
            'expires_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'Mã voucher đã tồn tại.',
            'code.regex' => 'Mã voucher chỉ được chứa chữ in hoa, số và dấu gạch ngang.',
            'code.min' => 'Mã voucher phải có ít nhất 4 ký tự.',
            'value.in' => 'Giá trị giảm phải là 5%, 10%, 15%, 20%, 25%, 30% hoặc 50%.',
            'expires_at.after_or_equal' => 'Hạn sử dụng không được là ngày trong quá khứ.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => mb_strtoupper(trim($this->string('code')->toString()))]);
        }
    }
}
