<?php

namespace App\Http\Requests\Admin;

class SaveBladeWarehouseRequest extends SaveWarehouseRequest
{
    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'Vui lòng nhập mã kho.',
            'code.regex' => 'Mã kho chỉ gồm chữ in hoa, số, dấu gạch ngang hoặc gạch dưới.',
            'code.unique' => 'Mã kho đã tồn tại.',
            'name.required' => 'Vui lòng nhập tên kho.',
            'timezone.timezone' => 'Múi giờ không hợp lệ.',
        ];
    }
}
