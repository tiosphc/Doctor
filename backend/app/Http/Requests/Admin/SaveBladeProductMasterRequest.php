<?php

namespace App\Http\Requests\Admin;

class SaveBladeProductMasterRequest extends SaveProductMasterRequest
{
    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'Mã là bắt buộc.',
            'code.regex' => 'Mã chỉ gồm chữ in hoa, số, dấu gạch ngang hoặc gạch dưới.',
            'code.unique' => 'Mã này đã tồn tại.',
            'name.required' => 'Tên là bắt buộc.',
            'parent_id.exists' => 'Danh mục cha không tồn tại.',
            'sort_order.min' => 'Thứ tự phải từ 0 trở lên.',
            'symbol.required' => 'Vui lòng nhập ký hiệu đơn vị.',
            'decimal_precision.required' => 'Vui lòng chọn số chữ số thập phân.',
            'decimal_precision.min' => 'Số chữ số thập phân phải từ 0 đến 3.',
            'decimal_precision.max' => 'Số chữ số thập phân phải từ 0 đến 3.',
        ];
    }
}
